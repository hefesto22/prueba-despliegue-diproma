<?php

namespace App\Services\Sales;

use App\Enums\CashMovementType;
use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Enums\TaxType;
use App\Exceptions\Cash\NoHayCajaAbiertaException;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Cash\CashSessionService;
use App\Services\Establishments\EstablishmentResolver;
use App\Services\Sales\Tax\SaleTaxCalculator;
use App\Services\Sales\Tax\TaxableLine;
use Illuminate\Support\Facades\DB;

class SaleService
{
    /**
     * F6a.5 — EstablishmentResolver inyectado para resolver la sucursal activa
     * cuando el caller no pasa una explícita. Reemplaza el fallback ciego a
     * `Establishment::main()` que ignoraba el `default_establishment_id` del
     * usuario autenticado y registraba ventas en la sucursal equivocada.
     *
     * C2 — CashSessionService inyectado para enforzar el invariante "toda venta
     * vive dentro de una sesión de caja abierta". Sin caja → no hay venta.
     * E.2.A2 — Además de abrir/cerrar caja, ahora expone
     * `recordMovementWithinTransaction()` que usamos para registrar el ingreso
     * por venta / la salida por anulación bajo la MISMA transacción del
     * SaleService (sin savepoints anidados).
     *
     * E.2.M1 — SaleTaxCalculator inyectado para centralizar la lógica fiscal
     * que antes estaba duplicada entre `calculateTotals()` y `PointOfSale::taxBreakdown()`.
     * Una sola fuente de verdad para subtotal/ISV/total con descuento.
     *
     * E.2.A2 — SaleInventoryProcessor inyectado para extraer el impacto de
     * inventario (lock + kardex + stock) fuera de `processSale` y `cancel`.
     * SaleService orquesta el ciclo de la venta; el processor se encarga del
     * inventario. SRP.
     */
    public function __construct(
        private readonly EstablishmentResolver $establishmentResolver,
        private readonly CashSessionService $cashSessionService,
        private readonly SaleTaxCalculator $taxCalculator,
        private readonly SaleInventoryProcessor $inventoryProcessor,
    ) {}

    /**
     * Procesar una venta completa desde el carrito del POS.
     *
     * Operación atómica:
     * 0. Validar que hay caja abierta en la sucursal (fail-fast fuera de la transacción)
     * 1. Auto-crear cliente si tiene RTN
     * 2. Crear la venta (Pendiente)
     * 3. `SaleInventoryProcessor::processCartItems` — lock productos, validar stock,
     *    crear SaleItems, registrar kardex SalidaVenta, decrementar stock
     * 4. `calculateTotals` — descomposición fiscal y descuento (vía SaleTaxCalculator)
     * 5. `CashSessionService::recordMovementWithinTransaction` — ingreso SaleIncome
     *    en la caja abierta (con lock para blindar contra cierre concurrente)
     * 6. Marcar como Completada
     *
     * @param  array  $cartItems  Array de ['product_id', 'quantity', 'unit_price', 'tax_type']
     * @param  PaymentMethod  $paymentMethod  Método de pago — obligatorio. Determina
     *                                        si la venta afecta el saldo físico de caja
     *                                        (solo `efectivo`) o queda como registro
     *                                        contable por método.
     * @param  string  $customerName  Nombre del cliente
     * @param  string|null  $customerRtn  RTN del cliente (null = consumidor final)
     * @param  DiscountType|null  $discountType  Tipo de descuento
     * @param  float|null  $discountValue  Valor del descuento
     * @param  string|null  $notes  Notas opcionales
     *
     * @throws \RuntimeException Si el carrito está vacío o no hay stock suficiente
     * @throws NoHayCajaAbiertaException Si no hay sesión de caja abierta en la sucursal
     */
    public function processSale(
        array $cartItems,
        PaymentMethod $paymentMethod,
        string $customerName = 'Consumidor Final',
        ?string $customerRtn = null,
        ?DiscountType $discountType = null,
        ?float $discountValue = null,
        ?string $notes = null,
        ?Establishment $establishment = null,
    ): Sale {
        if (empty($cartItems)) {
            throw new \RuntimeException('No se puede procesar una venta sin productos.');
        }

        // Resolver sucursal: usa la explícita o delega al EstablishmentResolver,
        // que aplica el orden correcto: default del user autenticado → matriz →
        // NoActiveEstablishmentException (nunca silenciosamente a la sucursal
        // equivocada, que corrompería el kardex y los libros SAR).
        $establishment ??= $this->establishmentResolver->resolve();

        // Fail-fast: si no hay caja abierta, no se arranca el procesamiento.
        // Hacer este check fuera de la transacción ahorra el rollback de un
        // trabajo que nunca debió comenzar. Dentro de la transacción re-
        // verificamos con lockForUpdate para cubrir el caso de cierre
        // concurrente entre este chequeo y la creación del movimiento.
        $this->cashSessionService->currentOpenSessionOrFail($establishment->id);

        return DB::transaction(function () use (
            $cartItems, $paymentMethod, $customerName, $customerRtn,
            $discountType, $discountValue, $notes, $establishment
        ) {
            // 1. Auto-crear cliente si tiene RTN
            $customerId = null;
            if (filled($customerRtn)) {
                $customer = $this->findOrCreateCustomer($customerName, $customerRtn);
                $customerId = $customer->id;
            }

            // 2. Crear la venta (pendiente)
            $sale = Sale::create([
                'establishment_id' => $establishment->id,
                'customer_id' => $customerId,
                'customer_name' => filled($customerName) ? $customerName : 'Consumidor Final',
                'customer_rtn' => $customerRtn,
                'date' => now(),
                'status' => SaleStatus::Pendiente,
                'payment_method' => $paymentMethod,
                'discount_type' => $discountType?->value,
                'discount_value' => $discountValue,
                'notes' => $notes,
            ]);

            // 3. Procesar items: lock, validar stock, crear SaleItems,
            //    registrar kardex de SalidaVenta y decrementar stock.
            //    El processor corre BAJO esta transacción — no abre la suya.
            $this->inventoryProcessor->processCartItems($sale, $cartItems, $establishment);

            // 4. Calcular totales fiscales (con descuento)
            $sale->load('items');
            $this->calculateTotals($sale, $discountType, $discountValue);

            // 5. Registrar el ingreso en la caja abierta.
            //    `recordMovementWithinTransaction` re-fetcha la sesión abierta
            //    con lockForUpdate dentro de NUESTRA transacción — blinda
            //    contra cierre concurrente entre el currentOpenSessionOrFail()
            //    de arriba y este punto, sin abrir un savepoint anidado.
            $this->cashSessionService->recordMovementWithinTransaction(
                establishmentId: $establishment->id,
                attributes: $this->buildSaleCashMovementAttributes(
                    sale: $sale,
                    type: CashMovementType::SaleIncome,
                    paymentMethod: $paymentMethod,
                    description: "Ingreso por venta {$sale->sale_number}",
                ),
            );

            // 6. Marcar como completada
            $sale->update(['status' => SaleStatus::Completada]);

            return $sale->fresh(['items.product', 'customer']);
        });
    }

    /**
     * Anular una venta.
     *
     * Si estaba completada:
     *   - Devuelve el stock (con kardex EntradaAnulacionVenta preservando costo histórico).
     *   - Registra un CashMovement::SaleCancellation en la caja abierta ACTUAL
     *     de la sucursal donde se vendió. Si la caja original ya cerró, la
     *     anulación queda en la sesión viva de hoy — la sesión cerrada es un
     *     registro histórico inmutable y no debe modificarse.
     *
     * Si estaba pendiente: solo marca anulada. Nunca hubo cash movement, no hay
     * nada que revertir ni stock que devolver.
     *
     * @throws \InvalidArgumentException Si la venta ya está anulada
     * @throws NoHayCajaAbiertaException Si la venta estaba completada y no hay
     *                                   caja abierta en la sucursal original.
     */
    public function cancel(Sale $sale): void
    {
        if (! $sale->status->canCancel()) {
            throw new \InvalidArgumentException('Esta venta ya está anulada.');
        }

        $wasCompleted = $sale->status === SaleStatus::Completada;

        // Fail-fast antes de abrir transacción: si la venta tuvo impacto en
        // caja (Completada), exigir caja abierta en la sucursal original.
        // Esto bloquea anulaciones cuando el cajero ya cerró — el flujo
        // correcto en ese caso es abrir la caja del día, anular allí, y la
        // sesión cerrada queda intacta como evidencia contable.
        if ($wasCompleted) {
            $this->cashSessionService->currentOpenSessionOrFail($sale->establishment_id);
        }

        DB::transaction(function () use ($sale, $wasCompleted) {
            if ($wasCompleted) {
                // Cargar items y establishment para que el processor pueda
                // iterar sin queries adicionales y atribuir la reversa de
                // kardex a la sucursal donde se vendió originalmente — no a
                // la sucursal activa del usuario que ejecuta la anulación.
                $sale->load(['items', 'establishment']);

                // Reversa de inventario: kardex EntradaAnulacionVenta con
                // costo histórico preservado + incremento de stock.
                $this->inventoryProcessor->revertForCancellation($sale, $sale->establishment);

                // Registrar la anulación en la caja abierta actual. Usa el
                // mismo payment_method que la venta original: si fue en
                // efectivo, resta del saldo físico; si fue en tarjeta, queda
                // como registro contable sin afectar el cajón.
                $this->cashSessionService->recordMovementWithinTransaction(
                    establishmentId: $sale->establishment_id,
                    attributes: $this->buildSaleCashMovementAttributes(
                        sale: $sale,
                        type: CashMovementType::SaleCancellation,
                        paymentMethod: $sale->payment_method,
                        description: "Anulación de venta {$sale->sale_number}",
                    ),
                );
            }

            $sale->update(['status' => SaleStatus::Anulada]);

            // Anular factura asociada si existe
            if ($sale->invoice) {
                $sale->invoice->void();
            }
        });
    }

    /**
     * Construye los atributos de un CashMovement asociado a una venta.
     *
     * E.2.A2 — Extraído para que el lock + create del movimiento los maneje
     * `CashSessionService::recordMovementWithinTransaction()`. Este service
     * solo sabe QUÉ registrar (venta, ingreso/cancelación, método, monto,
     * usuario) — el CashSessionService sabe CÓMO (lock la sesión abierta,
     * validar que no esté cerrada, persistir).
     *
     * @return array<string, mixed> Atributos del CashMovement (sin cash_session_id,
     *                              que lo resuelve el CashSessionService vía el lock).
     *
     * @throws \RuntimeException si no hay usuario autenticado ni `created_by`
     *         en la venta. Esto nunca debería pasar en el flujo normal —
     *         processSale corre siempre con usuario autenticado y cancel()
     *         también. Fail-fast si alguna vez se invoca desde un Job sin
     *         impersonation del usuario que originó la venta.
     */
    private function buildSaleCashMovementAttributes(
        Sale $sale,
        CashMovementType $type,
        PaymentMethod $paymentMethod,
        string $description,
    ): array {
        $userId = auth()->id() ?? $sale->created_by ?? throw new \RuntimeException(
            'No se puede registrar un movimiento de caja sin usuario autenticado.'
        );

        return [
            'user_id' => $userId,
            'type' => $type,
            'payment_method' => $paymentMethod,
            'amount' => $sale->total,
            'description' => $description,
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
        ];
    }

    /**
     * Calcular totales fiscales de la venta, incluyendo descuento.
     *
     * E.2.M1 — Delega la matemática fiscal a `SaleTaxCalculator`. Esta función
     * mantiene la responsabilidad de:
     *   1. Mapear los SaleItem a TaxableLine[] (con identity = item->id para
     *      poder mapear los LineBreakdown de vuelta a cada item).
     *   2. Resolver el `discountAmount` absoluto aplicando `DiscountType` sobre
     *      el grossTotal (política de descuento, ajena al calculator).
     *   3. Persistir el breakdown por línea en cada SaleItem y los totales
     *      agregados en el Sale. El calculator es puro — no toca DB.
     *
     * Antes del refactor esta función y `PointOfSale::taxBreakdown()` duplicaban
     * la misma lógica fiscal (descomposición base/ISV con descuento proporcional).
     * Un cambio en la regla (ej. ajuste de redondeo SAR) requería editar ambos
     * lugares con riesgo de desincronización — el calculator elimina esa trampa.
     */
    private function calculateTotals(
        Sale $sale,
        ?DiscountType $discountType,
        ?float $discountValue,
    ): void {
        // Mapear Eloquent items → TaxableLine[]. Identity = item->id para poder
        // mapear LineBreakdown de vuelta a cada item en el foreach de persistencia.
        $lines = $sale->items->map(function (SaleItem $item): TaxableLine {
            $taxType = $item->tax_type instanceof TaxType
                ? $item->tax_type
                : TaxType::from($item->tax_type);

            return new TaxableLine(
                unitPrice: (float) $item->unit_price,
                quantity: (int) $item->quantity,
                taxType: $taxType,
                identity: $item->id,
            );
        })->all();

        // Resolver discountAmount. DiscountType::calculateAmount necesita el
        // grossTotal — el calculator lo expone sin forzarnos a recorrer las
        // líneas dos veces ni duplicar la lógica de redondeo.
        $grossTotal = $this->taxCalculator->grossTotal($lines);
        $discountAmount = 0.0;
        if ($discountType && $discountValue !== null && $discountValue > 0) {
            $discountAmount = $discountType->calculateAmount($discountValue, $grossTotal);
        }

        $breakdown = $this->taxCalculator->calculate($lines, $discountAmount);

        // Persistir breakdown por línea. Lookup por identity (item->id) — los
        // LineBreakdown están en el mismo orden pero usamos lineFor() por
        // claridad y robustez contra reordenamientos futuros del calculator.
        foreach ($sale->items as $item) {
            $line = $breakdown->lineFor($item->id);
            if ($line === null) {
                continue; // never — el calculator garantiza una línea por item
            }

            $item->updateQuietly([
                'subtotal' => $line->subtotal,
                'isv_amount' => $line->isv,
                'total' => $line->total,
            ]);
        }

        // Persistir totales agregados post-descuento.
        $sale->updateQuietly([
            'discount_amount' => $breakdown->discountAmount,
            'subtotal' => $breakdown->subtotal,
            'isv' => $breakdown->isv,
            'total' => $breakdown->total,
        ]);
    }

    /**
     * Buscar cliente por RTN o crear uno nuevo automáticamente.
     */
    private function findOrCreateCustomer(string $name, string $rtn): Customer
    {
        return Customer::firstOrCreate(
            ['rtn' => $rtn],
            [
                'name' => $name,
                'is_active' => true,
            ]
        );
    }
}
