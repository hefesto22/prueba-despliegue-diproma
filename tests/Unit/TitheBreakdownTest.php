<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Finance\Exceptions\DeduccionDiezmoInvalidaException;
use App\Services\Finance\MonthlyProfit;
use App\Services\Finance\TitheBreakdown;
use PHPUnit\Framework\TestCase;

/**
 * Regla del diezmo: 10% de (utilidad neta − pagos agregados), nunca negativo.
 */
class TitheBreakdownTest extends TestCase
{
    /**
     * Utilidad neta = 3000 − 1500 − 300 − 200 = 1000.
     */
    private function profit(): MonthlyProfit
    {
        return new MonthlyProfit(
            year: 2026,
            month: 9,
            revenue: 3000.00,
            cost: 1500.00,
            expensePurchases: 300.00,
            cardFees: 200.00,
        );
    }

    public function test_sin_pagos_agregados_es_el_diez_por_ciento_de_la_utilidad_neta(): void
    {
        $breakdown = TitheBreakdown::from($this->profit(), []);

        $this->assertSame(1000.00, $breakdown->netProfit);
        $this->assertSame(1000.00, $breakdown->base());
        $this->assertSame(100.00, $breakdown->amount());
    }

    public function test_los_pagos_agregados_bajan_la_base(): void
    {
        $breakdown = TitheBreakdown::from($this->profit(), [250, '150.50']);

        $this->assertSame(400.50, $breakdown->extraDeductions);
        $this->assertSame(599.50, $breakdown->base());
        $this->assertSame(59.95, $breakdown->amount());
    }

    public function test_si_no_queda_ganancia_el_diezmo_es_cero(): void
    {
        $breakdown = TitheBreakdown::from($this->profit(), [1500]);

        $this->assertSame(-500.00, $breakdown->base());
        $this->assertSame(0.00, $breakdown->amount());
    }

    public function test_redondea_a_centavos(): void
    {
        $breakdown = TitheBreakdown::from($this->profit(), [0.05]);

        // base 999.95 × 10% = 99.995 → 100.00
        $this->assertSame(100.00, $breakdown->amount());
    }

    public function test_un_pago_negativo_se_rechaza(): void
    {
        $this->expectException(DeduccionDiezmoInvalidaException::class);

        TitheBreakdown::from($this->profit(), [-10]);
    }
}
