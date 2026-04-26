<?php

namespace Tests\Unit\Services\FiscalPeriods\ValueObjects;

use App\Services\FiscalPeriods\ValueObjects\IsvDeclarationTotals;
use DomainException;
use Tests\TestCase;

/**
 * Tests unitarios de IsvDeclarationTotals (ISV.4).
 *
 * Value Object puro — sin DB ni container. Todo el cómputo fiscal del
 * Formulario 201 SAR (Secciones A, B, C, D, E) se valida acá con tests
 * rápidos e independientes del resto del sistema.
 *
 * Cubre:
 *   1. Construcción canónica: cuadratura matemática (totales = gravados + exentos).
 *   2. Fórmula fiscal central: isv_a_pagar vs saldo_a_favor_siguiente según
 *      el signo de (debito − credito − retenciones − saldo_anterior).
 *   3. Validaciones de dominio: retenciones y saldo anterior nunca negativos.
 *   4. Aceptación de netos negativos (válidos por SAR cuando hay más NC que
 *      facturas en el período).
 *   5. Redondeo a 2 decimales — cuadratura SAR exige centavos, no sub-centavos.
 *   6. Helpers hasIsvAPagar() / hasSaldoAFavor() — mutuamente excluyentes.
 *   7. toArray() produce claves que coinciden con los fillable del modelo.
 */
class IsvDeclarationTotalsTest extends TestCase
{
    // ═══════════════════════════════════════════════════════
    // 1. Cuadratura de totales (A, B = gravados + exentos)
    // ═══════════════════════════════════════════════════════

    public function test_ventas_totales_es_la_suma_de_gravadas_mas_exentas(): void
    {
        $totals = IsvDeclarationTotals::calculate(
            ventasGravadas: 10_000.00,
            ventasExentas: 2_500.00,
            comprasGravadas: 0,
            comprasExentas: 0,
            isvDebitoFiscal: 0,
            isvCreditoFiscal: 0,
            isvRetencionesRecibidas: 0,
            saldoAFavorAnterior: 0,
        );

        $this->assertEquals(12_500.00, $totals->ventasTotales);
    }

    public function test_compras_totales_es_la_suma_de_gravadas_mas_exentas(): void
    {
        $totals = IsvDeclarationTotals::calculate(
            ventasGravadas: 0,
            ventasExentas: 0,
            comprasGravadas: 7_500.00,
            comprasExentas: 1_250.50,
            isvDebitoFiscal: 0,
            isvCreditoFiscal: 0,
            isvRetencionesRecibidas: 0,
            saldoAFavorAnterior: 0,
        );

        $this->assertEquals(8_750.50, $totals->comprasTotales);
    }

    // ═══════════════════════════════════════════════════════
    // 2. Fórmula fiscal: ISV a pagar vs saldo a favor siguiente
    // ═══════════════════════════════════════════════════════

    public function test_neto_positivo_produce_isv_a_pagar_y_saldo_siguiente_cero(): void
    {
        // debito 1500 − credito 500 − ret 0 − saldo_prev 0 = 1000 → a pagar
        $totals = IsvDeclarationTotals::calculate(
            ventasGravadas: 10_000,
            ventasExentas: 0,
            comprasGravadas: 3_333.33,
            comprasExentas: 0,
            isvDebitoFiscal: 1_500.00,
            isvCreditoFiscal: 500.00,
            isvRetencionesRecibidas: 0,
            saldoAFavorAnterior: 0,
        );

        $this->assertEquals(1_000.00, $totals->isvAPagar);
        $this->assertEquals(0.00, $totals->saldoAFavorSiguiente);
    }

    public function test_neto_negativo_produce_saldo_siguiente_y_isv_a_pagar_cero(): void
    {
        // debito 500 − credito 1500 = -1000 → saldo siguiente 1000
        $totals = IsvDeclarationTotals::calculate(
            ventasGravadas: 3_333.33,
            ventasExentas: 0,
            comprasGravadas: 10_000,
            comprasExentas: 0,
            isvDebitoFiscal: 500.00,
            isvCreditoFiscal: 1_500.00,
            isvRetencionesRecibidas: 0,
            saldoAFavorAnterior: 0,
        );

        $this->assertEquals(0.00, $totals->isvAPagar);
        $this->assertEquals(1_000.00, $totals->saldoAFavorSiguiente);
    }

    public function test_neto_cero_produce_isv_a_pagar_y_saldo_siguiente_ambos_cero(): void
    {
        // debito 1000 − credito 1000 = 0 → sin pagar ni arrastre
        $totals = IsvDeclarationTotals::calculate(
            ventasGravadas: 6_666.67,
            ventasExentas: 0,
            comprasGravadas: 6_666.67,
            comprasExentas: 0,
            isvDebitoFiscal: 1_000.00,
            isvCreditoFiscal: 1_000.00,
            isvRetencionesRecibidas: 0,
            saldoAFavorAnterior: 0,
        );

        $this->assertEquals(0.00, $totals->isvAPagar);
        $this->assertEquals(0.00, $totals->saldoAFavorSiguiente);
    }

    public function test_retenciones_recibidas_disminuyen_isv_a_pagar(): void
    {
        // debito 1500 − credito 500 − retenciones 300 = 700 → a pagar
        $totals = IsvDeclarationTotals::calculate(
            ventasGravadas: 10_000,
            ventasExentas: 0,
            comprasGravadas: 3_333.33,
            comprasExentas: 0,
            isvDebitoFiscal: 1_500.00,
            isvCreditoFiscal: 500.00,
            isvRetencionesRecibidas: 300.00,
            saldoAFavorAnterior: 0,
        );

        $this->assertEquals(700.00, $totals->isvAPagar);
        $this->assertEquals(0.00, $totals->saldoAFavorSiguiente);
    }

    public function test_saldo_a_favor_anterior_disminuye_isv_a_pagar(): void
    {
        // debito 1500 − credito 500 − saldo_prev 400 = 600 → a pagar
        $totals = IsvDeclarationTotals::calculate(
            ventasGravadas: 10_000,
            ventasExentas: 0,
            comprasGravadas: 3_333.33,
            comprasExentas: 0,
            isvDebitoFiscal: 1_500.00,
            isvCreditoFiscal: 500.00,
            isvRetencionesRecibidas: 0,
            saldoAFavorAnterior: 400.00,
        );

        $this->assertEquals(600.00, $totals->isvAPagar);
        $this->assertEquals(0.00, $totals->saldoAFavorSiguiente);
    }

    public function test_retenciones_y_saldo_previo_combinados_pueden_generar_saldo_siguiente(): void
    {
        // debito 1000 − credito 200 − retenciones 500 − saldo_prev 400 = -100 → saldo siguiente 100
        $totals = IsvDeclarationTotals::calculate(
            ventasGravadas: 6_666.67,
            ventasExentas: 0,
            comprasGravadas: 1_333.33,
            comprasExentas: 0,
            isvDebitoFiscal: 1_000.00,
            isvCreditoFiscal: 200.00,
            isvRetencionesRecibidas: 500.00,
            saldoAFavorAnterior: 400.00,
        );

        $this->assertEquals(0.00, $totals->isvAPagar);
        $this->assertEquals(100.00, $totals->saldoAFavorSiguiente);
    }

    // ═══════════════════════════════════════════════════════
    // 3. Validaciones de dominio (fail-fast)
    // ═══════════════════════════════════════════════════════

    public function test_lanza_excepcion_si_retenciones_recibidas_es_negativo(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('isv_retenciones_recibidas no puede ser negativo');

        IsvDeclarationTotals::calculate(
            ventasGravadas: 0,
            ventasExentas: 0,
            comprasGravadas: 0,
            comprasExentas: 0,
            isvDebitoFiscal: 0,
            isvCreditoFiscal: 0,
            isvRetencionesRecibidas: -50.00,
            saldoAFavorAnterior: 0,
        );
    }

    public function test_lanza_excepcion_si_saldo_a_favor_anterior_es_negativo(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('saldo_a_favor_anterior no puede ser negativo');

        IsvDeclarationTotals::calculate(
            ventasGravadas: 0,
            ventasExentas: 0,
            comprasGravadas: 0,
            comprasExentas: 0,
            isvDebitoFiscal: 0,
            isvCreditoFiscal: 0,
            isvRetencionesRecibidas: 0,
            saldoAFavorAnterior: -10.00,
        );
    }

    // ═══════════════════════════════════════════════════════
    // 4. Aceptación de netos negativos (caso SAR: más NC que facturas)
    // ═══════════════════════════════════════════════════════

    public function test_acepta_ventas_gravadas_negativas_cuando_nc_exceden_facturas(): void
    {
        // Período con más devoluciones (NC) que ventas nuevas — caso válido SAR
        $totals = IsvDeclarationTotals::calculate(
            ventasGravadas: -1_000.00,
            ventasExentas: 500.00,
            comprasGravadas: 0,
            comprasExentas: 0,
            isvDebitoFiscal: -150.00,  // ISV negativo también
            isvCreditoFiscal: 0,
            isvRetencionesRecibidas: 0,
            saldoAFavorAnterior: 0,
        );

        $this->assertEquals(-1_000.00, $totals->ventasGravadas);
        $this->assertEquals(-500.00, $totals->ventasTotales);
        // debito -150 − credito 0 = -150 → saldo siguiente 150
        $this->assertEquals(0.00, $totals->isvAPagar);
        $this->assertEquals(150.00, $totals->saldoAFavorSiguiente);
    }

    public function test_acepta_compras_gravadas_negativas_cuando_nc_exceden_facturas(): void
    {
        $totals = IsvDeclarationTotals::calculate(
            ventasGravadas: 0,
            ventasExentas: 0,
            comprasGravadas: -2_000.00,
            comprasExentas: 0,
            isvDebitoFiscal: 0,
            isvCreditoFiscal: -300.00,
            isvRetencionesRecibidas: 0,
            saldoAFavorAnterior: 0,
        );

        $this->assertEquals(-2_000.00, $totals->comprasGravadas);
        $this->assertEquals(-2_000.00, $totals->comprasTotales);
        // debito 0 − credito -300 = 300 → a pagar
        $this->assertEquals(300.00, $totals->isvAPagar);
        $this->assertEquals(0.00, $totals->saldoAFavorSiguiente);
    }

    // ═══════════════════════════════════════════════════════
    // 5. Redondeo a 2 decimales
    // ═══════════════════════════════════════════════════════

    public function test_redondea_todos_los_valores_a_2_decimales(): void
    {
        // Inputs: tercer decimal claramente <5 o >5 para evitar trampas de
        // representación binaria IEEE 754 con valores .005/.555 que pueden
        // redondear de forma sorpresiva según la versión de PHP.
        // Cada redondeo aquí es matemáticamente inequívoco.
        $totals = IsvDeclarationTotals::calculate(
            ventasGravadas: 1_234.561,           // → 1234.56 (down)
            ventasExentas: 500.128,              // → 500.13 (up)
            comprasGravadas: 100.123,            // → 100.12 (down)
            comprasExentas: 50.009,              // → 50.01 (up)
            isvDebitoFiscal: 185.187,            // → 185.19 (up)
            isvCreditoFiscal: 15.011,            // → 15.01 (down)
            isvRetencionesRecibidas: 10.004,     // → 10.00 (down)
            saldoAFavorAnterior: 5.998,          // → 6.00 (up)
        );

        // Inputs individuales: cada uno redondeado a 2 decimales en el VO.
        $this->assertEquals(1_234.56, $totals->ventasGravadas);
        $this->assertEquals(500.13, $totals->ventasExentas);
        $this->assertEquals(100.12, $totals->comprasGravadas);
        $this->assertEquals(50.01, $totals->comprasExentas);
        $this->assertEquals(185.19, $totals->isvDebitoFiscal);
        $this->assertEquals(15.01, $totals->isvCreditoFiscal);
        $this->assertEquals(10.00, $totals->isvRetencionesRecibidas);
        $this->assertEquals(6.00, $totals->saldoAFavorAnterior);

        // Totales derivados: se calculan con los inputs ORIGINALES (no los
        // ya redondeados) y luego se redondean. Verificar la suma cruda:
        //   ventasTotales  = round(1234.561 + 500.128,  2) = round(1734.689,  2) = 1734.69
        //   comprasTotales = round(100.123 + 50.009,    2) = round(150.132,   2) = 150.13
        $this->assertEquals(1_734.69, $totals->ventasTotales);
        $this->assertEquals(150.13, $totals->comprasTotales);

        // ISV derivado: neto = round(185.187 − 15.011 − 10.004 − 5.998, 2)
        //                    = round(154.174, 2) = 154.17 → a pagar
        $this->assertEquals(154.17, $totals->isvAPagar);
        $this->assertEquals(0.00, $totals->saldoAFavorSiguiente);
    }

    // ═══════════════════════════════════════════════════════
    // 6. Helpers mutuamente excluyentes
    // ═══════════════════════════════════════════════════════

    public function test_has_isv_a_pagar_y_has_saldo_a_favor_son_mutuamente_excluyentes_cuando_hay_pagar(): void
    {
        $totals = IsvDeclarationTotals::calculate(
            ventasGravadas: 10_000, ventasExentas: 0,
            comprasGravadas: 3_333.33, comprasExentas: 0,
            isvDebitoFiscal: 1_500, isvCreditoFiscal: 500,
            isvRetencionesRecibidas: 0, saldoAFavorAnterior: 0,
        );

        $this->assertTrue($totals->hasIsvAPagar());
        $this->assertFalse($totals->hasSaldoAFavor());
    }

    public function test_has_isv_a_pagar_y_has_saldo_a_favor_son_mutuamente_excluyentes_cuando_hay_saldo(): void
    {
        $totals = IsvDeclarationTotals::calculate(
            ventasGravadas: 3_333.33, ventasExentas: 0,
            comprasGravadas: 10_000, comprasExentas: 0,
            isvDebitoFiscal: 500, isvCreditoFiscal: 1_500,
            isvRetencionesRecibidas: 0, saldoAFavorAnterior: 0,
        );

        $this->assertFalse($totals->hasIsvAPagar());
        $this->assertTrue($totals->hasSaldoAFavor());
    }

    public function test_has_isv_a_pagar_y_has_saldo_a_favor_ambos_false_cuando_neto_cero(): void
    {
        $totals = IsvDeclarationTotals::calculate(
            ventasGravadas: 6_666.67, ventasExentas: 0,
            comprasGravadas: 6_666.67, comprasExentas: 0,
            isvDebitoFiscal: 1_000, isvCreditoFiscal: 1_000,
            isvRetencionesRecibidas: 0, saldoAFavorAnterior: 0,
        );

        $this->assertFalse($totals->hasIsvAPagar());
        $this->assertFalse($totals->hasSaldoAFavor());
    }

    // ═══════════════════════════════════════════════════════
    // 7. toArray() produce claves fillable del modelo
    // ═══════════════════════════════════════════════════════

    public function test_to_array_produce_claves_que_coinciden_con_fillable_del_modelo(): void
    {
        $totals = IsvDeclarationTotals::calculate(
            ventasGravadas: 10_000, ventasExentas: 2_000,
            comprasGravadas: 5_000, comprasExentas: 1_000,
            isvDebitoFiscal: 1_500, isvCreditoFiscal: 750,
            isvRetencionesRecibidas: 50, saldoAFavorAnterior: 25,
        );

        $array = $totals->toArray();

        // Claves snake_case que coinciden 1-a-1 con columnas DB / fillable del modelo.
        $this->assertArrayHasKey('ventas_gravadas', $array);
        $this->assertArrayHasKey('ventas_exentas', $array);
        $this->assertArrayHasKey('ventas_totales', $array);
        $this->assertArrayHasKey('compras_gravadas', $array);
        $this->assertArrayHasKey('compras_exentas', $array);
        $this->assertArrayHasKey('compras_totales', $array);
        $this->assertArrayHasKey('isv_debito_fiscal', $array);
        $this->assertArrayHasKey('isv_credito_fiscal', $array);
        $this->assertArrayHasKey('isv_retenciones_recibidas', $array);
        $this->assertArrayHasKey('saldo_a_favor_anterior', $array);
        $this->assertArrayHasKey('isv_a_pagar', $array);
        $this->assertArrayHasKey('saldo_a_favor_siguiente', $array);

        // Valores correctos
        $this->assertEquals(10_000.00, $array['ventas_gravadas']);
        $this->assertEquals(12_000.00, $array['ventas_totales']);
        // debito 1500 − credito 750 − ret 50 − saldo_prev 25 = 675
        $this->assertEquals(675.00, $array['isv_a_pagar']);
        $this->assertEquals(0.00, $array['saldo_a_favor_siguiente']);

        // Sin claves extra (el array debe ser exactamente 12 entradas).
        $this->assertCount(12, $array);
    }
}
