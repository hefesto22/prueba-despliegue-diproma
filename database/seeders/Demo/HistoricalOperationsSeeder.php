<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\DocumentType;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Enums\TaxType;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Cash\CashBalanceCalculator;
use App\Services\Cash\CashSessionService;
use App\Services\Expenses\ExpenseService;
use App\Services\Inventory\ProductStockLedger;
use App\Services\Invoicing\InvoiceService;
use App\Services\Purchases\PurchaseDocumentAmounts;
use App\Services\Purchases\PurchaseService;
use App\Services\Sales\SaleService;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Genera operaciones históricas realistas: ventas, compras, facturas, caja.
 *
 * Ventana temporal: 2026-02-01 → 2026-04-25 (≈84 días, 3 meses fiscales).
 *
 * Patrón diario (lunes a sábado, domingo cerrado):
 *   1. Sofía abre caja con L. 500.
 *   2. Genera 4-8 ventas durante el día con timestamps escalonados.
 *      - 75% Consumidor Final (sin Customer)
 *      - 15% cliente registrado con RTN (genera factura con CAI)
 *      - 10% cliente registrado sin RTN
 *      - Mix de payment_method (sin cheque por decisión del negocio):
 *        65% efectivo, 22% tarjeta crédito, 8% tarjeta débito, 5% transferencia
 *   3. Cada ~5 días, Carlos registra una compra (con CAI o Recibo Interno).
 *   4. Sofía cierra caja con monto exacto del expected (sin descuadre,
 *      flujo limpio para datos de demo).
 *   5. Algunas ventas se anulan el mismo día (5% anulación rate vía is_void
 *      en la factura — sin notas de crédito, decisión de negocio 2026-04-19).
 *
 * Tiempo congelado:
 *   - Carbon::setTestNow($fecha) en cada día → los servicios que usan now()
 *     (SaleService::processSale, CashSessionService::open, etc.) registran
 *     timestamps históricos correctos.
 *   - Se restablece a real al final.
 *
 * Reproducibilidad:
 *   - mt_srand con semilla fija al inicio. Re-correr el seeder produce el
 *     mismo set de ventas/compras (mismos productos, montos, clientes).
 *
 * Pre-requisitos (orquestados en RealisticHistoricalSeeder):
 *   - OperationalUsersSeeder (Sofía, Carlos, admin)
 *   - SuppliersDemoSeeder
 *   - CustomersDemoSeeder
 *   - CaiRangeDemoSeeder (CAI activo abr-jun 2026 con prefijo 001-001-01)
 *   - ProductSeeder (80 productos con stock=5)
 *
 * Idempotencia:
 *   - NO ES idempotente. Diseñado para correr UNA VEZ tras `migrate:fresh`.
 *   - Si se re-corre sin truncar tablas operativas, generará operaciones
 *     duplicadas con timestamps similares (no hace verificación de existencia).
 *   - Para re-poblar: php artisan migrate:fresh --seed && php artisan db:seed
 *     --class="Database\Seeders\Demo\RealisticHistoricalSeeder".
 */
class HistoricalOperationsSeeder extends Seeder
{
    private const RANDOM_SEED = 20260201;

    /** Monto de apertura diario en Lempiras. */
    private const OPENING_AMOUNT = 500.00;

    /** Stock mínimo que un producto debe tener para entrar a un carrito. */
    private const MIN_STOCK_FOR_SALE = 2;

    /** Probabilidad (en %) de anular una venta al final del día. */
    private const CANCEL_PROBABILITY_PERCENT = 5;

    /** Cada N días Carlos registra una compra. */
    private const PURCHASE_INTERVAL_DAYS = 5;

    /**
     * Inyectamos servicios en lugar de usar `app()` cada vez. Esto permite
     * trackear las dependencias del seeder explícitamente y facilita un
     * eventual test que mockee uno de los services.
     */
    public function __construct(
        private readonly SaleService $saleService,
        private readonly PurchaseService $purchaseService,
        private readonly InvoiceService $invoiceService,
        private readonly CashSessionService $cashSessionService,
        private readonly CashBalanceCalculator $cashCalculator,
        private readonly ExpenseService $expenseService,
        private readonly ProductStockLedger $stockLedger,
    ) {}

    public function run(): void
    {
        mt_srand(self::RANDOM_SEED);

        $sofia = User::where('email', 'sofia.lopez@diproma.hn')->firstOrFail();
        $carlos = User::where('email', 'carlos.mendoza@diproma.hn')->firstOrFail();
        $matriz = Establishment::where('is_main', true)->firstOrFail();

        // ─── Febrero 1: reposición inicial de stock ──────────────────────
        // Antes de empezar el loop de ventas necesitamos stock suficiente
        // para 80+ días de operación (feb + mar + abr). Una compra grande
        // con CAI a "El Sol" que multiplica el stock de cada producto activo.
        Carbon::setTestNow(Carbon::parse('2026-02-01 09:00:00'));
        Auth::login($carlos);
        $this->initialStockReplenishment($carlos, $matriz);

        // ─── Loop diario febrero 2 → abril 25 ────────────────────────────
        $start = Carbon::parse('2026-02-02');
        $end = Carbon::parse('2026-04-25');

        $purchaseDayCounter = 0;
        $totalSales = 0;
        $totalCancellations = 0;
        $totalInvoicesWithCai = 0;
        $totalPurchases = 0;

        for ($current = $start->copy(); $current->lessThanOrEqualTo($end); $current->addDay()) {
            // Diproma cierra los domingos.
            if ($current->isSunday()) {
                continue;
            }

            $stats = $this->processDay(
                date: $current,
                sofia: $sofia,
                carlos: $carlos,
                matriz: $matriz,
                purchaseDayCounter: $purchaseDayCounter,
            );

            $totalSales += $stats['sales'];
            $totalCancellations += $stats['cancellations'];
            $totalInvoicesWithCai += $stats['invoices_with_cai'];
            $totalPurchases += $stats['purchases'];
            $purchaseDayCounter++;
        }

        // Restablecer tiempo real para que el resto de los seeders y la
        // aplicación normal usen el reloj actual.
        Carbon::setTestNow();
        Auth::logout();

        $this->command?->info(sprintf(
            'Histórico generado: %d ventas (%d con CAI) | %d anulaciones | %d compras',
            $totalSales,
            $totalInvoicesWithCai,
            $totalCancellations,
            $totalPurchases,
        ));
    }

    // ─── Reposición inicial ──────────────────────────────────────────────

    /**
     * Reposición inicial: ~25 unidades a cada producto activo, respaldadas por
     * una factura de "El Sol". Asegura stock para todo el período histórico.
     *
     * Desde la Fase 1 (Compras = documento fiscal sin líneas) son dos cosas
     * separadas, igual que en la operación real: el stock entra por la ficha
     * del producto (ProductStockLedger → Kardex) y la factura se registra en
     * Compras solo con sus montos.
     */
    private function initialStockReplenishment(User $carlos, Establishment $matriz): void
    {
        $supplier = Supplier::where('rtn', '08019998765432')->firstOrFail(); // El Sol

        $restocked = $this->restockProducts(
            Product::active()->where('is_service', false)->get(),
            // 25 unidades para nuevos (gravado), 15 para usados (exento).
            fn (Product $product): int => $product->tax_type === TaxType::Gravado15 ? 25 : 15,
        );

        $this->registerPurchaseDocument(
            carlos: $carlos,
            matriz: $matriz,
            supplier: $supplier,
            documentType: SupplierDocumentType::Factura,
            supplierInvoiceNumber: '001-001-01-00102345',
            supplierCai: 'B7C3D8-1A2B3C-4D5E6F-7A8B9C-0D1E2F-AB',
            restocked: $restocked,
            notes: 'Reposición inicial de stock — temporada marzo 2026.',
        );
    }

    // ─── Loop diario ─────────────────────────────────────────────────────

    /**
     * Procesa un día completo: abrir caja, ventas, compra ocasional, cierre.
     *
     * @return array{sales:int,cancellations:int,invoices_with_cai:int,purchases:int}
     */
    private function processDay(
        Carbon $date,
        User $sofia,
        User $carlos,
        Establishment $matriz,
        int $purchaseDayCounter,
    ): array {
        $stats = ['sales' => 0, 'cancellations' => 0, 'invoices_with_cai' => 0, 'purchases' => 0];

        // 1. Abrir caja a las 8:00 AM (Sofía).
        Carbon::setTestNow($date->copy()->setTime(8, 0));
        Auth::login($sofia);
        $session = $this->cashSessionService->open($matriz->id, $sofia, self::OPENING_AMOUNT);

        // 2. Ventas durante el día (4-8 ventas con timestamps escalonados).
        $saleCount = mt_rand(4, 8);
        $salesToday = [];

        for ($i = 0; $i < $saleCount; $i++) {
            // Distribuir ventas entre 9:00 y 17:30 — incrementos de ~1h.
            $saleTime = $date->copy()->setTime(9, 0)->addMinutes(intval(($i * 510) / max(1, $saleCount - 1)));
            Carbon::setTestNow($saleTime);

            $sale = $this->processOneSale($matriz, $stats);
            if ($sale !== null) {
                $salesToday[] = $sale;
            }
        }

        // 3. Compra ocasional (cada N días) — Carlos al medio día.
        if ($purchaseDayCounter % self::PURCHASE_INTERVAL_DAYS === 0 && $purchaseDayCounter > 0) {
            Carbon::setTestNow($date->copy()->setTime(12, 30));
            Auth::login($carlos);
            $this->processOnePurchase($carlos, $matriz);
            $stats['purchases']++;
            // Volver a Sofía para el resto del día.
            Auth::login($sofia);
        }

        // 4. Gastos chicos del día (efectivo) — Sofía registra gasolina/papelería
        //    al volver de mandados. ~30% de probabilidad por día.
        if (mt_rand(1, 100) <= 30) {
            Carbon::setTestNow($date->copy()->setTime(15, 0));
            $this->processSmallCashExpense($sofia, $matriz);
        }

        // 5. Anulación ocasional (5% de probabilidad por venta del día).
        Carbon::setTestNow($date->copy()->setTime(17, 45));
        foreach ($salesToday as $sale) {
            if (mt_rand(1, 100) <= self::CANCEL_PROBABILITY_PERCENT) {
                try {
                    $this->saleService->cancel($sale->fresh());
                    $stats['cancellations']++;
                    // Una anulación por día máximo — patrón realista.
                    break;
                } catch (\Throwable) {
                    // Si la anulación falla (factura ya inválida, etc.) seguir sin abortar.
                }
            }
        }

        // 6. Cerrar caja a las 18:00 con monto exacto (sin descuadre).
        Carbon::setTestNow($date->copy()->setTime(18, 0));
        $sessionFresh = $session->fresh();
        $expected = $this->cashCalculator->expectedCash($sessionFresh);
        $this->cashSessionService->close(
            session: $sessionFresh,
            closedBy: $sofia,
            actualClosingAmount: $expected,
            notes: null,
            authorizedBy: null,
        );

        return $stats;
    }

    // ─── Generación de venta ─────────────────────────────────────────────

    /**
     * Procesa una venta con valores aleatorios (carrito, cliente, pago).
     * Si genera factura con CAI, incrementa el contador en $stats.
     *
     * Retorna la Sale procesada o null si no hubo stock suficiente.
     */
    private function processOneSale(Establishment $matriz, array &$stats): ?Sale
    {
        $cart = $this->buildRandomCart();
        if (empty($cart)) {
            return null;
        }

        // Distribución del cliente.
        $clientType = mt_rand(1, 100);
        $customerName = 'Consumidor Final';
        $customerRtn = null;
        $shouldGenerateInvoiceWithCai = false;

        if ($clientType <= 15) {
            // Cliente con RTN — factura con CAI.
            $customer = Customer::whereNotNull('rtn')->where('is_active', true)->inRandomOrder()->first();
            if ($customer !== null) {
                $customerName = $customer->name;
                $customerRtn = $customer->rtn;
                $shouldGenerateInvoiceWithCai = true;
            }
        } elseif ($clientType <= 25) {
            // Cliente registrado sin RTN.
            $customer = Customer::whereNull('rtn')->where('is_active', true)->inRandomOrder()->first();
            if ($customer !== null) {
                $customerName = $customer->name;
            }
        }
        // Resto: Consumidor Final.

        $paymentMethod = $this->randomPaymentMethod();

        try {
            $sale = $this->saleService->processSale(
                cartItems: $cart,
                paymentMethod: $paymentMethod,
                customerName: $customerName,
                customerRtn: $customerRtn,
                discountType: null,
                discountValue: null,
                notes: null,
                establishment: $matriz,
            );
        } catch (\Throwable) {
            // Stock insuficiente / error puntual — no abortar el día.
            return null;
        }

        $stats['sales']++;

        // Generar factura con CAI para clientes con RTN.
        if ($shouldGenerateInvoiceWithCai) {
            try {
                $this->invoiceService->generateFromSale(
                    sale: $sale,
                    withoutCai: false,
                    establishmentId: $matriz->id,
                    documentType: DocumentType::Factura,
                );
                $stats['invoices_with_cai']++;
            } catch (\Throwable) {
                // CAI agotado / vencido — registrar venta sin factura formal.
            }
        }

        return $sale;
    }

    /**
     * Construye un carrito aleatorio: 1-3 productos con stock disponible,
     * cantidades 1-2 por línea.
     *
     * @return array<int, array{product_id:int,quantity:int,unit_price:float,tax_type:\App\Enums\TaxType}>
     */
    private function buildRandomCart(): array
    {
        $itemCount = mt_rand(1, 3);

        $products = Product::active()
            ->where('stock', '>', self::MIN_STOCK_FOR_SALE)
            ->inRandomOrder()
            ->limit($itemCount)
            ->get();

        if ($products->isEmpty()) {
            return [];
        }

        $cart = [];
        foreach ($products as $product) {
            // Cantidad: 1 (más común) o 2 (ocasional).
            $qty = mt_rand(1, 100) <= 80 ? 1 : 2;
            // Nunca exceder stock real.
            $qty = min($qty, (int) $product->stock - 1);
            if ($qty <= 0) {
                continue;
            }

            $cart[] = [
                'product_id' => $product->id,
                'quantity' => $qty,
                // SaleService espera unit_price CON ISV (convención del cart
                // del POS). La BD guarda sale_price NETO; el accessor
                // sale_price_with_isv lo reconstruye respetando tax_type.
                'unit_price' => (float) $product->sale_price_with_isv,
                'tax_type' => $product->tax_type,
            ];
        }

        return $cart;
    }

    /**
     * Distribución realista de payment_method para una tienda de electrónicos
     * en Honduras: efectivo domina en montos pequeños, tarjeta crece con
     * tickets más grandes. Cheque excluido por decisión del negocio
     * (Diproma no acepta cheques — riesgo de impago).
     */
    private function randomPaymentMethod(): PaymentMethod
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 65 => PaymentMethod::Efectivo,
            $roll <= 87 => PaymentMethod::TarjetaCredito,
            $roll <= 95 => PaymentMethod::TarjetaDebito,
            default => PaymentMethod::Transferencia,
        };
    }

    // ─── Generación de compra ────────────────────────────────────────────

    /**
     * Carlos registra una compra mediana de 3-5 productos. Mix:
     *   - 80% con factura (CAI del proveedor).
     *   - 20% Recibo Interno (proveedor genérico, sin CAI).
     *
     * Igual que en la reposición inicial, el stock entra por la ficha del
     * producto y la compra registra solo el documento con sus montos.
     */
    private function processOnePurchase(User $carlos, Establishment $matriz): void
    {
        $useReciboInterno = mt_rand(1, 100) <= 20;

        $products = Product::active()
            ->where('is_service', false)
            ->inRandomOrder()
            ->limit(mt_rand(3, 5))
            ->get();

        if ($products->isEmpty()) {
            return;
        }

        $restocked = $this->restockProducts($products, fn (): int => mt_rand(3, 8));

        if ($useReciboInterno) {
            $this->registerPurchaseDocument(
                carlos: $carlos,
                matriz: $matriz,
                supplier: Supplier::forInternalReceipts(),
                documentType: SupplierDocumentType::ReciboInterno,
                supplierInvoiceNumber: 'RI-'.Carbon::today()->format('Ymd').'-'.mt_rand(100, 999),
                supplierCai: null,
                restocked: $restocked,
            );

            return;
        }

        $this->registerPurchaseDocument(
            carlos: $carlos,
            matriz: $matriz,
            supplier: Supplier::operational()->where('is_active', true)->inRandomOrder()->firstOrFail(),
            documentType: SupplierDocumentType::Factura,
            supplierInvoiceNumber: sprintf('001-001-01-%08d', mt_rand(100000, 999999)),
            supplierCai: $this->generateCaiString(),
            restocked: $restocked,
        );
    }

    /**
     * Sube el stock de cada producto como lo haría el operador desde la ficha,
     * dejando su asiento en el Kardex.
     *
     * @param  Collection<int, Product>  $products
     * @param  Closure(Product): int  $quantityFor
     * @return list<array{product: Product, quantity: int}>
     */
    private function restockProducts(Collection $products, Closure $quantityFor): array
    {
        $restocked = [];

        foreach ($products as $product) {
            $quantity = $quantityFor($product);
            $previousStock = (int) $product->stock;

            $product->update(['stock' => $previousStock + $quantity]);
            $this->stockLedger->recordManualAdjustment($product, $previousStock);

            $restocked[] = ['product' => $product, 'quantity' => $quantity];
        }

        return $restocked;
    }

    /**
     * Registra y confirma el documento de compra que respalda un reabastecimiento.
     *
     * Los montos se derivan del costo NETO de lo recibido: la base gravada
     * lleva su 15% de ISV encima; en Recibo Interno todo va como exento.
     *
     * @param  list<array{product: Product, quantity: int}>  $restocked
     */
    private function registerPurchaseDocument(
        User $carlos,
        Establishment $matriz,
        Supplier $supplier,
        SupplierDocumentType $documentType,
        string $supplierInvoiceNumber,
        ?string $supplierCai,
        array $restocked,
        ?string $notes = null,
    ): void {
        $taxable = 0.0;
        $exempt = 0.0;

        foreach ($restocked as ['product' => $product, 'quantity' => $quantity]) {
            $lineCost = round((float) $product->cost_price * $quantity, 2);

            if ($product->tax_type === TaxType::Gravado15) {
                $taxable += $lineCost;
            } else {
                $exempt += $lineCost;
            }
        }

        $amounts = PurchaseDocumentAmounts::forDocument(
            $documentType,
            $taxable,
            $exempt,
            PurchaseDocumentAmounts::suggestedIsv($taxable),
        );

        $purchase = Purchase::create([
            'establishment_id' => $matriz->id,
            'supplier_id' => $supplier->id,
            'document_type' => $documentType,
            'supplier_invoice_number' => $supplierInvoiceNumber,
            'supplier_cai' => $supplierCai,
            'date' => Carbon::today(),
            // Forzado a 0 mientras no exista Cuentas por Pagar.
            'credit_days' => 0,
            'notes' => $notes,
            'created_by' => $carlos->id,
            // status explícito: el default SQL no se hidrata al modelo en
            // memoria y PurchaseService::confirm() necesita leerlo.
            'status' => PurchaseStatus::Borrador,
            ...$amounts->toAttributes(),
        ]);

        try {
            $this->purchaseService->confirm($purchase);
        } catch (\Throwable) {
            // Período cerrado por algún seed previo: la compra queda en
            // Borrador. El observer fiscal protege la integridad.
        }
    }

    // ─── Gastos chicos del día ───────────────────────────────────────────

    /**
     * Sofía registra un gasto chico del día (gasolina, papelería, mensajería)
     * con efectivo del cajón. El ExpenseService crea el Expense + el
     * CashMovement bajo la sesión abierta — ambos ligados por expense_id.
     */
    private function processSmallCashExpense(User $sofia, Establishment $matriz): void
    {
        // Mix realista de tipos de gasto chico.
        $expenseTypes = [
            [
                'category' => ExpenseCategory::Combustible,
                'description' => 'Gasolina entrega a cliente',
                'amount_min' => 80, 'amount_max' => 250,
                'has_invoice' => true,
            ],
            [
                'category' => ExpenseCategory::Papeleria,
                'description' => 'Papel térmico para impresora',
                'amount_min' => 40, 'amount_max' => 120,
                'has_invoice' => true,
            ],
            [
                'category' => ExpenseCategory::Mensajeria,
                'description' => 'Envío encomienda — Cargo Expreso',
                'amount_min' => 50, 'amount_max' => 200,
                'has_invoice' => false,
            ],
            [
                'category' => ExpenseCategory::Otros,
                'description' => 'Café y refrigerios — atención cliente',
                'amount_min' => 30, 'amount_max' => 100,
                'has_invoice' => false,
            ],
        ];

        $type = $expenseTypes[array_rand($expenseTypes)];
        $amount = mt_rand($type['amount_min'], $type['amount_max']);

        $attributes = [
            'establishment_id' => $matriz->id,
            'user_id' => $sofia->id,
            'expense_date' => Carbon::today(),
            'category' => $type['category'],
            'payment_method' => PaymentMethod::Efectivo,
            'amount_total' => $amount,
            'description' => $type['description'],
            'is_isv_deductible' => $type['has_invoice'],
            'created_by' => $sofia->id,
        ];

        if ($type['has_invoice']) {
            // Factura con CAI: ExpenseService la copia al Libro de Compras
            // (ExpenseFiscalDocumentSync), como en la operación real.
            $isvBase = round($amount / 1.15, 2);
            $attributes['taxable_amount'] = $isvBase;
            $attributes['isv_amount'] = round($amount - $isvBase, 2);
            $attributes['provider_invoice_cai'] = $this->generateCaiString();
            $attributes['provider_invoice_date'] = Carbon::today();
            $attributes['provider_name'] = match ($type['category']) {
                ExpenseCategory::Combustible => 'Gasolinera UNO La Granja',
                ExpenseCategory::Papeleria => 'Librería Universal',
                default => 'Proveedor varios',
            };
            $attributes['provider_rtn'] = '08019970000001';
            $attributes['provider_invoice_number'] = sprintf('001-001-01-%08d', mt_rand(50000, 99999));
        }

        try {
            $this->expenseService->register($attributes);
        } catch (\Throwable) {
            // Tolerancia: si el registro falla por alguna validación
            // inesperada, no abortar el día completo.
        }
    }

    /**
     * Genera un CAI con formato SAR real (37 caracteres: 6-6-6-6-6-2 hex con guiones).
     *
     * Formato oficial: XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XX
     *   = 32 hex chars + 5 guiones = 37 chars (cabe en supplier_cai varchar(37))
     *
     * Determinista para reproducibilidad — usa mt_rand con seed fijo del seeder.
     */
    private function generateCaiString(): string
    {
        return sprintf(
            '%s-%s-%s-%s-%s-%s',
            strtoupper(dechex(mt_rand(0x100000, 0xFFFFFF))),
            strtoupper(dechex(mt_rand(0x100000, 0xFFFFFF))),
            strtoupper(dechex(mt_rand(0x100000, 0xFFFFFF))),
            strtoupper(dechex(mt_rand(0x100000, 0xFFFFFF))),
            strtoupper(dechex(mt_rand(0x100000, 0xFFFFFF))),
            strtoupper(sprintf('%02X', mt_rand(0x10, 0xFF))),
        );
    }
}
