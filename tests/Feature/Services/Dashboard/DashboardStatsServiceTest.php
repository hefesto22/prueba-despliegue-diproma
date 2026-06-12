<?php

namespace Tests\Feature\Services\Dashboard;

use App\Enums\MovementType;
use App\Enums\SaleStatus;
use App\Enums\TaxType;
use App\Models\Category;
use App\Models\Expense;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Dashboard\DashboardStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Tests para `DashboardStatsService::netProfitThisMonth()`.
 *
 * La utilidad neta = ganancia bruta − gastos operativos del mes. Es el
 * numerito que el cliente usa para saber si el negocio ganó o perdió
 * plata después de pagar todo. Cualquier regresión acá se traduce en
 * decisiones de negocio mal informadas — los tests fijan el contrato.
 *
 * Convenciones del test:
 *   - Las ventas se construyen con SaleItem + InventoryMovement SalidaVenta
 *     porque grossProfitThisMonth hace JOIN con esos movimientos para sacar
 *     el costo histórico (no usa product.cost_price directamente).
 *   - Limpiamos cache entre escenarios para que cada test mida su propio
 *     estado (sin esto, la métrica del primer test se reusa en los demás).
 */
class DashboardStatsServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMatriz;

    private DashboardStatsService $service;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        // El trait CreatesMatriz ya inicializó $this->matriz en su propio setUp
        // gracias al patrón initializeTraits de Laravel TestCase.
        $this->service = app(DashboardStatsService::class);
        $this->category = Category::factory()->create();

        // Aislar caché entre tests — DashboardStatsService usa Cache::remember
        // con TTL 5min; sin flush los valores del setUp anterior reaparecen.
        Cache::flush();
    }

    public function test_sin_ventas_ni_gastos_devuelve_ceros(): void
    {
        $result = $this->service->netProfitThisMonth();

        $this->assertEquals(0.0, $result['gross_profit']);
        $this->assertEquals(0.0, $result['expenses']);
        $this->assertEquals(0.0, $result['net_profit']);
        $this->assertEquals(0.0, $result['net_margin_percent']);
        $this->assertEquals(0.0, $result['revenue']);
    }

    public function test_sin_gastos_utilidad_neta_igual_a_ganancia_bruta(): void
    {
        // Venta de L 1,000 base con producto a costo L 600 → ganancia bruta 400
        $this->createCompletedSaleThisMonth(unitPriceWithIsv: 1150, costPrice: 600);

        $result = $this->service->netProfitThisMonth();

        $this->assertEquals(400.00, $result['gross_profit'],
            'Ganancia bruta = 1000 base − 600 costo = 400.');
        $this->assertEquals(0.00, $result['expenses'],
            'Sin gastos registrados.');
        $this->assertEquals(400.00, $result['net_profit'],
            'Sin gastos: utilidad neta = ganancia bruta.');
        $this->assertEquals(40.00, $result['net_margin_percent'],
            'Margen neto = 400 / 1000 = 40%.');
    }

    public function test_con_gastos_menores_a_ganancia_bruta_utilidad_neta_positiva(): void
    {
        // Ganancia bruta: 1000 base − 600 costo = 400
        $this->createCompletedSaleThisMonth(unitPriceWithIsv: 1150, costPrice: 600);

        // Gasto del mes: L 100
        Expense::factory()->create([
            'establishment_id' => $this->matriz->id,
            'expense_date' => Carbon::now()->toDateString(),
            'amount_total' => 100.00,
        ]);

        $result = $this->service->netProfitThisMonth();

        $this->assertEquals(400.00, $result['gross_profit']);
        $this->assertEquals(100.00, $result['expenses']);
        $this->assertEquals(300.00, $result['net_profit'],
            'Utilidad neta = 400 ganancia bruta − 100 gastos = 300.');
        $this->assertEquals(30.00, $result['net_margin_percent'],
            'Margen neto = 300 / 1000 = 30%.');
    }

    public function test_con_gastos_mayores_a_ganancia_bruta_utilidad_neta_negativa(): void
    {
        // Ganancia bruta: 1000 base − 600 costo = 400
        $this->createCompletedSaleThisMonth(unitPriceWithIsv: 1150, costPrice: 600);

        // Gastos del mes: L 700 (mayor que la ganancia bruta de 400)
        Expense::factory()->create([
            'establishment_id' => $this->matriz->id,
            'expense_date' => Carbon::now()->toDateString(),
            'amount_total' => 700.00,
        ]);

        $result = $this->service->netProfitThisMonth();

        $this->assertEquals(-300.00, $result['net_profit'],
            'Utilidad neta NEGATIVA: 400 − 700 = −300. El negocio perdió plata.');
        $this->assertEquals(-30.00, $result['net_margin_percent'],
            'Margen neto negativo: −300 / 1000 = −30%.');
    }

    public function test_gasto_de_mes_anterior_no_cuenta_en_utilidad_del_mes_actual(): void
    {
        // Ganancia bruta del mes actual: 400
        $this->createCompletedSaleThisMonth(unitPriceWithIsv: 1150, costPrice: 600);

        // Gasto registrado el mes pasado — NO debe restar a este mes.
        Expense::factory()->create([
            'establishment_id' => $this->matriz->id,
            'expense_date' => Carbon::now()->subMonthNoOverflow()->toDateString(),
            'amount_total' => 999.00,
        ]);

        // Gasto del mes actual: L 50
        Expense::factory()->create([
            'establishment_id' => $this->matriz->id,
            'expense_date' => Carbon::now()->toDateString(),
            'amount_total' => 50.00,
        ]);

        $result = $this->service->netProfitThisMonth();

        $this->assertEquals(50.00, $result['expenses'],
            'Solo el gasto del mes actual cuenta — el de hace un mes se excluye.');
        $this->assertEquals(350.00, $result['net_profit'],
            'Utilidad neta = 400 − 50 = 350.');
    }

    public function test_suma_multiples_gastos_del_mes_actual(): void
    {
        $this->createCompletedSaleThisMonth(unitPriceWithIsv: 1150, costPrice: 600);

        // 3 gastos en distintos días del mes actual
        $today = Carbon::now();
        $amounts = [120.00, 75.50, 45.25];
        foreach ($amounts as $amount) {
            Expense::factory()->create([
                'establishment_id' => $this->matriz->id,
                'expense_date' => $today->toDateString(),
                'amount_total' => $amount,
            ]);
        }

        $result = $this->service->netProfitThisMonth();

        $this->assertEquals(240.75, $result['expenses'],
            'Suma de 120 + 75.50 + 45.25 = 240.75.');
        $this->assertEquals(159.25, $result['net_profit'],
            'Utilidad neta = 400 ganancia − 240.75 gastos = 159.25.');
    }

    public function test_gastos_no_deducibles_tambien_restan_utilidad(): void
    {
        // Para P&L (utilidad neta) no importa si el gasto es deducible de
        // ISV o no — TODO gasto reduce ganancia. El flag is_isv_deductible
        // solo afecta el ISV-353 (crédito fiscal).
        $this->createCompletedSaleThisMonth(unitPriceWithIsv: 1150, costPrice: 600);

        $today = Carbon::now()->toDateString();

        Expense::factory()->create([
            'establishment_id' => $this->matriz->id,
            'expense_date' => $today,
            'amount_total' => 100.00,
            'is_isv_deductible' => true, // con factura, deducible
        ]);

        Expense::factory()->create([
            'establishment_id' => $this->matriz->id,
            'expense_date' => $today,
            'amount_total' => 80.00,
            'is_isv_deductible' => false, // sin factura, no deducible (ej. taxi)
        ]);

        $result = $this->service->netProfitThisMonth();

        $this->assertEquals(180.00, $result['expenses'],
            'Ambos gastos suman: 100 deducible + 80 no deducible = 180.');
        $this->assertEquals(220.00, $result['net_profit'],
            'Utilidad neta = 400 − 180 = 220.');
    }

    // ─── Líneas de reparación (sin producto del catálogo) ───────────────

    public function test_honorarios_y_pieza_externa_entran_a_ganancia_bruta(): void
    {
        // Venta tipo "entrega de reparación": honorarios exentos sin costo
        // (ganancia pura) + pieza externa gravada con costo registrado.
        $sale = Sale::factory()->create([
            'establishment_id' => $this->matriz->id,
            'date' => Carbon::now(),
            'status' => SaleStatus::Completada,
            'subtotal' => 1500.00, // 500 honorarios + 1000 base pieza
            'isv' => 150.00,
            'total' => 1650.00,
            'discount_amount' => 0,
        ]);

        // Honorarios: exentos, sin producto, sin costo
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => null,
            'description' => 'Honorarios por reparación',
            'quantity' => 1,
            'unit_price' => 500.00,
            'unit_cost' => null,
            'tax_type' => TaxType::Exento,
            'subtotal' => 500.00,
            'isv_amount' => 0,
            'total' => 500.00,
        ]);

        // Pieza externa nueva: 1150 con ISV (base 1000), costó 400
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => null,
            'description' => 'Pantalla 14" nueva',
            'quantity' => 1,
            'unit_price' => 1150.00,
            'unit_cost' => 400.00,
            'tax_type' => TaxType::Gravado15,
            'subtotal' => 1000.00,
            'isv_amount' => 150.00,
            'total' => 1150.00,
        ]);

        $result = $this->service->grossProfitThisMonth();

        $this->assertEquals(1500.00, $result['revenue'],
            'Revenue = 500 honorarios + 1000 base de pieza (sin ISV).');
        $this->assertEquals(400.00, $result['cost'],
            'Costo = solo el de la pieza externa; honorarios sin costo.');
        $this->assertEquals(1100.00, $result['gross_profit'],
            'Ganancia = (500 − 0) honorarios + (1000 − 400) pieza = 1100.');
    }

    public function test_linea_de_producto_sin_kardex_sigue_excluida(): void
    {
        // Guard de honestidad estadística: una línea CON producto pero SIN
        // movimiento de kardex (venta pre-migración) no debe entrar al
        // cálculo ni como revenue ni como costo — aunque ahora el JOIN sea LEFT.
        $product = Product::factory()->brandNew()->inCategory($this->category)->create([
            'cost_price' => 600,
            'sale_price' => 1000,
            'stock' => 10,
            'tax_type' => TaxType::Gravado15,
        ]);

        $sale = Sale::factory()->create([
            'establishment_id' => $this->matriz->id,
            'date' => Carbon::now(),
            'status' => SaleStatus::Completada,
            'subtotal' => 1000.00,
            'isv' => 150.00,
            'total' => 1150.00,
            'discount_amount' => 0,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 1150.00,
            'tax_type' => TaxType::Gravado15,
            'subtotal' => 1000.00,
            'isv_amount' => 150.00,
            'total' => 1150.00,
        ]);
        // Sin InventoryMovement SalidaVenta a propósito.

        $result = $this->service->grossProfitThisMonth();

        $this->assertEquals(0.00, $result['revenue'],
            'Línea de producto sin kardex se excluye: no se inventan costos.');
        $this->assertEquals(0.00, $result['gross_profit']);
    }

    public function test_mezcla_pos_y_reparacion_suma_ambas_fuentes_de_costo(): void
    {
        // POS: 1000 base − 600 costo (kardex) = 400
        $this->createCompletedSaleThisMonth(unitPriceWithIsv: 1150, costPrice: 600);

        // Reparación: honorarios exentos 300 sin costo = 300
        $sale = Sale::factory()->create([
            'establishment_id' => $this->matriz->id,
            'date' => Carbon::now(),
            'status' => SaleStatus::Completada,
            'subtotal' => 300.00,
            'isv' => 0,
            'total' => 300.00,
            'discount_amount' => 0,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => null,
            'description' => 'Honorarios por mantenimiento',
            'quantity' => 1,
            'unit_price' => 300.00,
            'unit_cost' => null,
            'tax_type' => TaxType::Exento,
            'subtotal' => 300.00,
            'isv_amount' => 0,
            'total' => 300.00,
        ]);

        $result = $this->service->grossProfitThisMonth();

        $this->assertEquals(1300.00, $result['revenue'], '1000 POS + 300 honorarios.');
        $this->assertEquals(600.00, $result['cost'], 'Solo el costo del kardex POS.');
        $this->assertEquals(700.00, $result['gross_profit'], '400 POS + 300 honorarios.');
    }

    // ─── Helpers ─────────────────────────────────────────────

    /**
     * Crear venta completada del mes actual con su SaleItem y el movimiento
     * SalidaVenta que captura el costo histórico (cómo lo hace SaleService
     * en producción). grossProfitThisMonth depende de ese movimiento para
     * calcular el costo — no se puede shortcut con product.cost_price.
     */
    private function createCompletedSaleThisMonth(
        float $unitPriceWithIsv,
        float $costPrice
    ): Sale {
        $product = Product::factory()->brandNew()->inCategory($this->category)->create([
            'cost_price' => $costPrice,
            'sale_price' => round($unitPriceWithIsv / 1.15, 2),
            'stock' => 10,
            'tax_type' => TaxType::Gravado15,
        ]);

        $unitBase = round($unitPriceWithIsv / 1.15, 2);
        $unitIsv = round($unitPriceWithIsv - $unitBase, 2);

        $sale = Sale::factory()->create([
            'establishment_id' => $this->matriz->id,
            'date' => Carbon::now(),
            'status' => SaleStatus::Completada,
            'subtotal' => $unitBase,
            'isv' => $unitIsv,
            'total' => $unitPriceWithIsv,
            'discount_amount' => 0,
        ]);

        SaleItem::factory()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $unitPriceWithIsv,
            'tax_type' => TaxType::Gravado15,
            'subtotal' => $unitBase,
            'isv_amount' => $unitIsv,
            'total' => $unitPriceWithIsv,
        ]);

        // Movimiento SalidaVenta con unit_cost = cost_price (NETO) — igual
        // que SaleInventoryProcessor en producción. grossProfitThisMonth
        // usa este unit_cost para el COGS.
        InventoryMovement::create([
            'establishment_id' => $this->matriz->id,
            'product_id' => $product->id,
            'type' => MovementType::SalidaVenta,
            'quantity' => 1,
            'unit_cost' => $costPrice,
            'stock_before' => 10,
            'stock_after' => 9,
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
        ]);

        return $sale;
    }
}
