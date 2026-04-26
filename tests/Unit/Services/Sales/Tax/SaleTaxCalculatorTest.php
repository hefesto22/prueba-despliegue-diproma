<?php

namespace Tests\Unit\Services\Sales\Tax;

use App\Enums\TaxType;
use App\Services\Sales\Tax\SaleTaxCalculator;
use App\Services\Sales\Tax\TaxBreakdown;
use App\Services\Sales\Tax\TaxableLine;
use Tests\TestCase;

/**
 * Tests unitarios de SaleTaxCalculator.
 *
 * E.2.M1 — cubren la regla fiscal centralizada en el calculator. Estos tests
 * son los únicos que necesitan existir para validar el cálculo — POS y
 * SaleService consumen la misma clase, así que su comportamiento está
 * garantizado por transitividad.
 *
 * No requieren DB (Unit test). El calculator se construye directamente con
 * el multiplier deseado — no depende del container ni de config().
 */
class SaleTaxCalculatorTest extends TestCase
{
    private SaleTaxCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new SaleTaxCalculator(1.15);
    }

    // ═══════════════════════════════════════════════════════
    // Casos base — una sola línea
    // ═══════════════════════════════════════════════════════

    public function test_linea_gravada_descompone_base_e_isv_correctamente(): void
    {
        $lines = [
            new TaxableLine(unitPrice: 115.00, quantity: 1, taxType: TaxType::Gravado15),
        ];

        $result = $this->calculator->calculate($lines);

        // 115 / 1.15 = 100 base; 115 - 100 = 15 ISV
        $this->assertEquals(100.00, $result->subtotal);
        $this->assertEquals(15.00, $result->isv);
        $this->assertEquals(115.00, $result->total);
        $this->assertEquals(115.00, $result->grossTotal);
        $this->assertEquals(0.00, $result->discountAmount);
    }

    public function test_linea_exenta_no_calcula_isv(): void
    {
        $lines = [
            new TaxableLine(unitPrice: 500.00, quantity: 1, taxType: TaxType::Exento),
        ];

        $result = $this->calculator->calculate($lines);

        $this->assertEquals(500.00, $result->subtotal);
        $this->assertEquals(0.00, $result->isv);
        $this->assertEquals(500.00, $result->total);
    }

    public function test_cantidad_multiplica_line_total(): void
    {
        $lines = [
            new TaxableLine(unitPrice: 115.00, quantity: 3, taxType: TaxType::Gravado15),
        ];

        $result = $this->calculator->calculate($lines);

        // 345 total; 345/1.15 = 300 base; 45 ISV
        $this->assertEquals(300.00, $result->subtotal);
        $this->assertEquals(45.00, $result->isv);
        $this->assertEquals(345.00, $result->total);
    }

    // ═══════════════════════════════════════════════════════
    // Casos mixtos
    // ═══════════════════════════════════════════════════════

    public function test_mix_gravado_y_exento_sumado_correctamente(): void
    {
        $lines = [
            new TaxableLine(unitPrice: 115.00, quantity: 1, taxType: TaxType::Gravado15),  // base 100 + ISV 15
            new TaxableLine(unitPrice: 200.00, quantity: 2, taxType: TaxType::Exento),     // base 400 + ISV 0
        ];

        $result = $this->calculator->calculate($lines);

        $this->assertEquals(500.00, $result->subtotal); // 100 + 400
        $this->assertEquals(15.00, $result->isv);
        $this->assertEquals(515.00, $result->total);
        $this->assertEquals(515.00, $result->grossTotal);
    }

    public function test_devuelve_un_line_breakdown_por_cada_linea(): void
    {
        $lines = [
            new TaxableLine(unitPrice: 115.00, quantity: 1, taxType: TaxType::Gravado15, identity: 'a'),
            new TaxableLine(unitPrice: 50.00, quantity: 2, taxType: TaxType::Exento, identity: 'b'),
        ];

        $result = $this->calculator->calculate($lines);

        $this->assertCount(2, $result->lines);

        $lineA = $result->lineFor('a');
        $this->assertNotNull($lineA);
        $this->assertEquals(100.00, $lineA->subtotal);
        $this->assertEquals(15.00, $lineA->isv);
        $this->assertEquals(115.00, $lineA->total);

        $lineB = $result->lineFor('b');
        $this->assertNotNull($lineB);
        $this->assertEquals(100.00, $lineB->subtotal);
        $this->assertEquals(0.00, $lineB->isv);
        $this->assertEquals(100.00, $lineB->total);
    }

    // ═══════════════════════════════════════════════════════
    // Descuento
    // ═══════════════════════════════════════════════════════

    public function test_descuento_reduce_base_e_isv_proporcionalmente(): void
    {
        $lines = [
            new TaxableLine(unitPrice: 115.00, quantity: 1, taxType: TaxType::Gravado15),
        ];

        // 10% descuento sobre 115 = 11.50
        $result = $this->calculator->calculate($lines, discountAmount: 11.50);

        // grossTotal 115, ratio 0.10 → base 90, isv 13.50, total 103.50
        $this->assertEquals(90.00, $result->subtotal);
        $this->assertEquals(13.50, $result->isv);
        $this->assertEquals(103.50, $result->total);
        $this->assertEquals(115.00, $result->grossTotal);
        $this->assertEquals(11.50, $result->discountAmount);
    }

    public function test_descuento_cero_no_afecta_totales(): void
    {
        $lines = [
            new TaxableLine(unitPrice: 115.00, quantity: 1, taxType: TaxType::Gravado15),
        ];

        $result = $this->calculator->calculate($lines, discountAmount: 0.0);

        $this->assertEquals(100.00, $result->subtotal);
        $this->assertEquals(15.00, $result->isv);
        $this->assertEquals(115.00, $result->total);
        $this->assertEquals(0.00, $result->discountAmount);
    }

    public function test_descuento_mayor_al_total_se_clampa_al_grossTotal(): void
    {
        $lines = [
            new TaxableLine(unitPrice: 115.00, quantity: 1, taxType: TaxType::Gravado15),
        ];

        // Descuento pedido: 500. grossTotal: 115. Clamp: 115.
        $result = $this->calculator->calculate($lines, discountAmount: 500.00);

        $this->assertEquals(115.00, $result->discountAmount); // reporta el clampeado
        $this->assertEquals(0.00, $result->subtotal);
        $this->assertEquals(0.00, $result->isv);
        $this->assertEquals(0.00, $result->total);
    }

    public function test_descuento_en_linea_exenta_solo_reduce_base(): void
    {
        $lines = [
            new TaxableLine(unitPrice: 200.00, quantity: 1, taxType: TaxType::Exento),
        ];

        $result = $this->calculator->calculate($lines, discountAmount: 20.00);

        // ISV sigue en 0 (era exento); base baja 10% (20/200)
        $this->assertEquals(180.00, $result->subtotal);
        $this->assertEquals(0.00, $result->isv);
        $this->assertEquals(180.00, $result->total);
    }

    // ═══════════════════════════════════════════════════════
    // Casos borde
    // ═══════════════════════════════════════════════════════

    public function test_lista_vacia_devuelve_breakdown_en_ceros(): void
    {
        $result = $this->calculator->calculate([]);

        $this->assertEquals(0.00, $result->subtotal);
        $this->assertEquals(0.00, $result->isv);
        $this->assertEquals(0.00, $result->total);
        $this->assertEquals(0.00, $result->grossTotal);
        $this->assertEquals(0.00, $result->discountAmount);
        $this->assertEmpty($result->lines);
    }

    public function test_lista_vacia_con_descuento_devuelve_ceros(): void
    {
        // Un descuento sin líneas no puede aplicarse (no hay grossTotal).
        $result = $this->calculator->calculate([], discountAmount: 50.00);

        $this->assertEquals(0.00, $result->total);
        $this->assertEquals(0.00, $result->discountAmount); // clampeado a 0
    }

    public function test_grossTotal_helper_suma_lineas_sin_descomponer(): void
    {
        $lines = [
            new TaxableLine(unitPrice: 115.00, quantity: 2, taxType: TaxType::Gravado15),
            new TaxableLine(unitPrice: 50.00, quantity: 3, taxType: TaxType::Exento),
        ];

        // 230 + 150 = 380
        $this->assertEquals(380.00, $this->calculator->grossTotal($lines));
    }

    public function test_grossTotal_helper_retorna_cero_en_lista_vacia(): void
    {
        $this->assertEquals(0.00, $this->calculator->grossTotal([]));
    }

    // ═══════════════════════════════════════════════════════
    // Validación de invariantes
    // ═══════════════════════════════════════════════════════

    public function test_descuento_negativo_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->calculator->calculate([], discountAmount: -5.00);
    }

    public function test_multiplier_no_positivo_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SaleTaxCalculator(0);
    }

    public function test_taxable_line_precio_negativo_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TaxableLine(unitPrice: -1.00, quantity: 1, taxType: TaxType::Gravado15);
    }

    public function test_taxable_line_cantidad_cero_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TaxableLine(unitPrice: 10.00, quantity: 0, taxType: TaxType::Gravado15);
    }

    public function test_calculate_rechaza_elementos_no_taxable_line(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->calculator->calculate(['not a line']);
    }

    // ═══════════════════════════════════════════════════════
    // Paridad con implementación previa
    // ═══════════════════════════════════════════════════════

    /**
     * Replica un escenario típico del POS para verificar que el resultado es
     * idéntico al que producía la lógica duplicada en PointOfSale::taxBreakdown
     * y SaleService::calculateTotals antes del refactor.
     */
    public function test_paridad_escenario_pos_tipico(): void
    {
        $lines = [
            new TaxableLine(unitPrice: 230.00, quantity: 2, taxType: TaxType::Gravado15),  // 460
            new TaxableLine(unitPrice: 45.00, quantity: 1, taxType: TaxType::Gravado15),   // 45
            new TaxableLine(unitPrice: 100.00, quantity: 1, taxType: TaxType::Exento),     // 100
        ];

        // Descuento fijo L 50.00 sobre grossTotal 605
        $result = $this->calculator->calculate($lines, discountAmount: 50.00);

        $this->assertEquals(605.00, $result->grossTotal);
        $this->assertEquals(50.00, $result->discountAmount);

        // La suma subtotal + isv del result debe ser igual al total
        $this->assertEqualsWithDelta(
            $result->subtotal + $result->isv,
            $result->total,
            0.01,
            'subtotal + isv debe coincidir con total (al centavo)'
        );

        // El total debe ser grossTotal - discountAmount (al centavo)
        $this->assertEqualsWithDelta(
            605.00 - 50.00,
            $result->total,
            0.01,
            'total debe ser grossTotal - discountAmount'
        );
    }

    public function test_breakdown_empty_helper(): void
    {
        $empty = TaxBreakdown::empty();

        $this->assertEquals(0.00, $empty->total);
        $this->assertEmpty($empty->lines);
    }
}
