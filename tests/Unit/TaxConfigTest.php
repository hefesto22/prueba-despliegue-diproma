<?php

namespace Tests\Unit;

use App\Enums\TaxType;
use Tests\TestCase;

class TaxConfigTest extends TestCase
{
    /**
     * La tasa ISV estándar es 15% (0.15 decimal).
     */
    public function test_isv_standard_rate_is_fifteen_percent(): void
    {
        $gravado = TaxType::Gravado15;

        // rate() devuelve la tasa decimal del config (fallback a 0.15)
        $this->assertEquals(0.15, $gravado->rate());
        $this->assertEquals(15, $gravado->percentage());
        $this->assertEquals(1.15, $gravado->multiplier());
    }

    /**
     * Exento devuelve 0 en todas las métricas.
     */
    public function test_exento_returns_zero(): void
    {
        $exento = TaxType::Exento;

        $this->assertEquals(0.0, $exento->rate());
        $this->assertEquals(0, $exento->percentage());
        $this->assertEquals(1.0, $exento->multiplier());
    }

    /**
     * Cálculo de ISV: subtotal * rate.
     */
    public function test_isv_calculation_is_correct(): void
    {
        $rate = TaxType::Gravado15->rate();

        // L 1,000.00 * 0.15 = L 150.00
        $this->assertEquals(150.00, round(1000.00 * $rate, 2));

        // L 5,432.10 * 0.15 = L 814.82 (redondeado)
        $this->assertEquals(814.82, round(5432.10 * $rate, 2));

        // Exento: L 1,000.00 * 0.0 = L 0.00
        $this->assertEquals(0.00, round(1000.00 * TaxType::Exento->rate(), 2));
    }

    /**
     * Conversión base → con ISV y viceversa.
     */
    public function test_price_conversion_with_multiplier(): void
    {
        $multiplier = TaxType::Gravado15->multiplier();

        // Base L 1,000 → Con ISV L 1,150
        $this->assertEquals(1150.00, round(1000.00 * $multiplier, 2));

        // Con ISV L 1,150 → Base L 1,000
        $this->assertEquals(1000.00, round(1150.00 / $multiplier, 2));
    }
}
