<?php

namespace App\Services\Repairs;

use App\Enums\CashMovementType;
use App\Enums\MovementType;
use App\Enums\PaymentMethod;
use App\Enums\RepairItemSource;
use App\Enums\RepairLogEvent;
use App\Enums\RepairStatus;
use App\Enums\SaleStatus;
use App\Events\RepairDelivered;
use App\Exceptions\Repairs\InsufficientStockOnDeliveryException;
use App\Exceptions\Repairs\RepairDeliveryException;
use App\Exceptions\Repairs\RepairTransitionException;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Repair;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Cash\CashSessionService;
use App\Services\Establishments\EstablishmentResolver;
use App\Services\Invoicing\InvoiceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Orquestador de la entrega final de una Reparación.
 *
 * La entrega es la transición más crítica del módulo Repairs: combina
 * facturación CAI, descuento de inventario, ingreso a caja y borrado
 * programado de fotos. Todo bajo UNA transacción atómica para garantizar
 * que un fallo en cualquier paso deje el sistema coherente (rollback).
 *
 * No reusamos `SaleService::processSale` directamente porque:
 *   - SaleService registra el ingreso en caja por el TOTAL de la venta.
 *   - Las reparaciones pueden tener anticipo cobrado (ya en caja desde
 *     que se aprobó la cotización). Registrar el total completo aquí
 *     sería contar el anticipo dos veces.
 *   - La caja al entregar SOLO debe registrar el SALDO restante
 *     (total - advance_payment).
 *
 * Reutilizamos sí:
 *   - `InvoiceService::generateFromSale` para emitir la Factura CAI con
 *     todo el flujo SAR estándar (correlativo, integrity_hash, sellos).
 *   - `CashSessionService::recordMovementWithinTransaction` para el
 *     movimiento de caja del saldo, con `RepairFinalIncome`.
 *   - El esquema de InventoryMovement existente para el kardex.
 *
 * Atomicidad: todo dentro de `DB::transaction`. El evento `RepairDelivered`
 * se dispara FUERA de la transacción tras el commit, para que los
 * listeners (cleanup de fotos en F-R6) solo corran si la entrega persistió.
 */
class RepairDeliveryService
{
    public function __construct(
        private readonly CashSessionService $cashSessionService,
        private readonly EstablishmentResolver $establishmentResolver,
        private readonly InvoiceService $invoiceService,
    ) {}

    /**
     * Entregar una reparación al cliente.
     *
     * Pre-condiciones (validadas fail-fast antes de la transacción):
     *   - status = ListoEntrega.
     *   - Tiene al menos un item.
     *   - Si hay saldo > 0 con payment_method efectivo: caja abierta.
     *
     * Efectos atómicos (dentro de la transacción):
     *   1. Crea Sale (status Completada al final) con SaleItems mapeados.
     *   2. Descuenta stock + kardex de las piezas internas (PiezaInventario).
     *      Bloqueo duro si stock insuficiente — el caller debe reemplazar
     *      la pieza o anular la reparación.
     *   3. Emite Invoice CAI vía InvoiceService.
     *   4. Registra ingreso en caja por el SALDO (total - advance_payment).
     *      Si el anticipo SUPERA el total (caso edge: cotización ajustada
     *      a la baja después de cobrar anticipo), registra el excedente
     *      como `RepairAdvanceRefund`.
     *   5. Actualiza el Repair: status=Entregada, sale_id, invoice_id,
     *      delivered_at, customer_rtn (si se editó).
     *   6. Registra evento `StatusChange` en repair_status_logs.
     *
     * @throws RepairTransitionException                 Si el estado no permite entrega.
     * @throws RepairDeliveryException                   Si el repair no tiene items.
     * @throws InsufficientStockOnDeliveryException      Si una pieza interna ya no tiene stock.
     * @throws \App\Exceptions\Cash\NoHayCajaAbiertaException Si requiere caja y no hay.
     */
    public function deliver(
        Repair $repair,
        PaymentMethod $paymentMethod,
        ?string $customerRtnOverride = null,
        bool $withoutCai = false,
        ?string $note = null,
    ): Repair {
        // ─── Validaciones fail-fast ───────────────────────────────────
        if (! $repair->status->canTransitionTo(RepairStatus::Entregada)) {
            throw new RepairTransitionException(
                from: $repair->status,
                to: RepairStatus::Entregada,
            );
        }

        $repair->loadMissing('items');

        if ($repair->items->count() === 0) {
            throw new RepairDeliveryException(
                'No se puede entregar una reparación sin líneas de cotización.'
            );
        }

        // Resolver establishment (consistente con resto del proyecto).
        $establishment = $repair->establishment_id
            ? $repair->establishment
            : $this->establishmentResolver->resolve();

        // Calcular saldo a cobrar al entregar.
        $total = (float) $repair->total;
        $advance = (float) $repair->advance_payment;
        $outstanding = round($total - $advance, 2);
        $advanceExcess = $outstanding < 0 ? abs($outstanding) : 0.0;
        $outstanding = max(0.0, $outstanding);

        // Si el saldo es > 0 Y se paga en efectivo, exigir caja abierta.
        // Si no afecta caja (ej: tarjeta), igual registramos movimiento
        // contable, pero no validamos caja físicamente.
        if (($outstanding > 0 || $advanceExcess > 0) && $paymentMethod->affectsCashBalance()) {
            $this->cashSessionService->currentOpenSessionOrFail($establishment->id);
        }

        // ─── Transacción atómica ──────────────────────────────────────
        $delivered = DB::transaction(function () use (
            $repair, $paymentMethod, $customerRtnOverride, $withoutCai,
            $note, $establishment, $total, $advance, $outstanding, $advanceExcess,
        ) {
            $previousStatus = $repair->status;

            // 1. Resolver RTN final y customer_id
            $finalRtn = $customerRtnOverride !== null
                ? trim($customerRtnOverride) ?: null
                : $repair->customer_rtn;

            $customerId = $repair->customer_id;
            // Si el cliente cambió de RTN al entregar y no había customer_id,
            // auto-creamos el Customer (consistente con SaleService::findOrCreateCustomer).
            if (! $customerId && filled($finalRtn)) {
                $customer = Customer::firstOrCreate(
                    ['rtn' => $finalRtn],
                    [
                        'name' => $repair->customer_name,
                        'phone' => $repair->customer_phone,
                        'is_active' => true,
                    ]
                );
                $customerId = $customer->id;
            }

            // 2. Crear Sale (status pendiente — se marca Completada al final)
            $sale = Sale::create([
                'establishment_id' => $establishment->id,
                'customer_id' => $customerId,
                'customer_name' => $repair->customer_name,
                'customer_rtn' => $finalRtn,
                'date' => now(),
                'status' => SaleStatus::Pendiente,
                'payment_method' => $paymentMethod,
                'discount_type' => null,
                'discount_value' => null,
                'discount_amount' => 0,
                'subtotal' => $repair->subtotal,
                'isv' => $repair->isv,
                'total' => $repair->total,
                'notes' => "Generada al entregar reparación {$repair->repair_number}",
            ]);

            // 3. Crear SaleItems mapeados desde RepairItems + descontar stock
            //    de piezas internas. Recorrido único, atomicidad garantizada.
            //
            //    SaleItem.product_id queda null para honorarios y piezas
            //    externas (no están en el catálogo). En esos casos guardamos
            //    `description` para que la factura pueda nombrarlos.
            //    Para PiezaInventario hay product_id y `description` queda null
            //    (la factura usará Product->name).
            foreach ($repair->items as $item) {
                $saleItem = SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $item->product_id, // null si no es PiezaInventario
                    'description' => $item->product_id ? null : $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'tax_type' => $item->tax_type,
                    'subtotal' => $item->subtotal,
                    'isv_amount' => $item->isv_amount,
                    'total' => $item->total,
                ]);

                if ($item->source === RepairItemSource::PiezaInventario) {
                    $this->decrementProductStock(
                        productId: $item->product_id,
                        quantity: (float) $item->quantity,
                        sale: $sale,
                        establishment: $establishment,
                    );
                }
            }

            // 4. Marcar Sale como Completada (sin pasar por SaleService porque
            //    NO queremos registrar el ingreso por TOTAL — eso lo hacemos
            //    abajo por SALDO).
            $sale->update(['status' => SaleStatus::Completada]);

            // 5. Emitir Invoice CAI usando el service existente
            $invoice = $this->invoiceService->generateFromSale(
                sale: $sale->fresh(['items']),
                withoutCai: $withoutCai,
                establishmentId: $establishment->id,
            );

            // 6. Registrar movimiento(s) de caja
            //    - Si saldo > 0: ingreso `RepairFinalIncome` por el saldo.
            //    - Si advanceExcess > 0: egreso `RepairAdvanceRefund` por el excedente.
            //      Caso edge: cliente había dejado anticipo > total final.
            if ($outstanding > 0) {
                $this->cashSessionService->recordMovementWithinTransaction(
                    establishmentId: $establishment->id,
                    attributes: [
                        'user_id' => Auth::id() ?? $repair->created_by,
                        'type' => CashMovementType::RepairFinalIncome->value,
                        'payment_method' => $paymentMethod->value,
                        'amount' => $outstanding,
                        'description' => sprintf(
                            'Saldo de reparación %s (total %s − anticipo %s)',
                            $repair->repair_number,
                            number_format($total, 2),
                            number_format($advance, 2),
                        ),
                        'reference_type' => Repair::class,
                        'reference_id' => $repair->id,
                        'occurred_at' => now(),
                    ],
                );
            }

            if ($advanceExcess > 0) {
                $this->cashSessionService->recordMovementWithinTransaction(
                    establishmentId: $establishment->id,
                    attributes: [
                        'user_id' => Auth::id() ?? $repair->created_by,
                        'type' => CashMovementType::RepairAdvanceRefund->value,
                        'payment_method' => PaymentMethod::Efectivo->value,
                        'amount' => $advanceExcess,
                        'description' => sprintf(
                            'Devolución de excedente de anticipo en reparación %s',
                            $repair->repair_number,
                        ),
                        'reference_type' => Repair::class,
                        'reference_id' => $repair->id,
                        'occurred_at' => now(),
                    ],
                );
            }

            // 7. Actualizar Repair: estado terminal + vínculos fiscales.
            $repair->update([
                'status' => RepairStatus::Entregada,
                'delivered_at' => now(),
                'sale_id' => $sale->id,
                'invoice_id' => $invoice->id,
                'customer_id' => $customerId, // por si auto-creamos cliente al entregar
                'customer_rtn' => $finalRtn,
            ]);

            // 8. Registrar el StatusChange en la bitácora del repair.
            $repair->statusLogs()->create([
                'event_type' => RepairLogEvent::StatusChange,
                'from_status' => $previousStatus->value,
                'to_status' => RepairStatus::Entregada->value,
                'changed_by' => Auth::id(),
                'metadata' => [
                    'sale_id' => $sale->id,
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'outstanding_charged' => number_format($outstanding, 2, '.', ''),
                    'advance_refunded' => number_format($advanceExcess, 2, '.', ''),
                ],
                'note' => $note,
            ]);

            return $repair->fresh();
        });

        // 9. Disparar evento DESPUÉS del commit — listeners (cleanup de fotos)
        //    solo deben correr si la entrega quedó realmente persistida.
        RepairDelivered::dispatch($delivered);

        return $delivered;
    }

    /**
     * Descontar stock + registrar kardex de una pieza de inventario.
     *
     * Replica la lógica de `SaleInventoryProcessor::processCartItems` pero
     * SIN crear SaleItem (ya lo creamos en el caller con datos del RepairItem).
     *
     * @throws InsufficientStockOnDeliveryException si el stock actual < cantidad cotizada.
     */
    private function decrementProductStock(
        int $productId,
        float $quantity,
        Sale $sale,
        \App\Models\Establishment $establishment,
    ): void {
        $product = Product::where('id', $productId)
            ->lockForUpdate()
            ->firstOrFail();

        $quantityInt = (int) $quantity;

        if ($product->stock < $quantityInt) {
            throw new InsufficientStockOnDeliveryException(
                product: $product,
                requested: $quantity,
                available: $product->stock,
            );
        }

        // Snapshot del CPP actual ANTES del decremento — congelado en kardex.
        InventoryMovement::record(
            product: $product,
            type: MovementType::SalidaVenta,
            quantity: $quantityInt,
            reference: $sale,
            notes: "Venta {$sale->sale_number} (entrega de reparación)",
            unitCost: (float) $product->cost_price,
            establishment: $establishment,
        );

        $product->update(['stock' => $product->stock - $quantityInt]);
    }
}
