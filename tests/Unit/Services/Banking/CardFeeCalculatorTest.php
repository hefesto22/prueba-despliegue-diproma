<?php

namespace Tests\Unit\Services\Banking;

use App\Enums\PaymentMethod;
use App\Models\CompanySetting;
use App\Services\Banking\CardFeeCalculator;
use Tests\TestCase;

/**
 * Tests unitarios del CardFeeCalculator.
 *
 * Por qué `Tests\TestCase` (no PHPUnit\Framework\TestCase puro):
 *   El calculator depende de un closure que resuelve `CompanySetting`. Para
 *   testear la lógica pura (cálculos, decisiones de aplicación) usamos un
 *   stub in-memory — no hace falta booteo de Laravel ni base de datos. Pero
 *   heredamos de Tests\TestCase por consistencia con el resto del suite y
 *   por si algún día agregamos un test que sí necesite boot.
 */
class CardFeeCalculatorTest extends TestCase
{
    private CardFeeCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        // Stub in-memory de CompanySetting con tasas conocidas — no toca BD.
        $settings = new CompanySetting();
        $settings->card_fee_rate_credit = 0.0340;
        $settings->card_fee_rate_debit = 0.0250;

        $this->calculator = new CardFeeCalculator(
            settingsResolver: fn () => $settings,
        );
    }

    // ─── appliesTo() ─────────────────────────────────────────

    public function test_applies_to_tarjeta_credito(): void
    {
        $this->assertTrue($this->calculator->appliesTo(PaymentMethod::TarjetaCredito));
    }

    public function test_applies_to_tarjeta_debito(): void
    {
        $this->assertTrue($this->calculator->appliesTo(PaymentMethod::TarjetaDebito));
    }

    public function test_does_not_apply_to_efectivo(): void
    {
        $this->assertFalse($this->calculator->appliesTo(PaymentMethod::Efectivo));
    }

    public function test_does_not_apply_to_transferencia(): void
    {
        $this->assertFalse($this->calculator->appliesTo(PaymentMethod::Transferencia));
    }

    public function test_does_not_apply_to_cheque(): void
    {
        $this->assertFalse($this->calculator->appliesTo(PaymentMethod::Cheque));
    }

    // ─── calculate() ─────────────────────────────────────────

    public function test_calculates_credit_fee_at_3_4_percent(): void
    {
        // 5000 × 0.0340 = 170.00
        $fee = $this->calculator->calculate(PaymentMethod::TarjetaCredito, 5000.00);
        $this->assertEqualsWithDelta(170.00, $fee, 0.001);
    }

    public function test_calculates_debit_fee_at_2_5_percent(): void
    {
        // 5000 × 0.0250 = 125.00
        $fee = $this->calculator->calculate(PaymentMethod::TarjetaDebito, 5000.00);
        $this->assertEqualsWithDelta(125.00, $fee, 0.001);
    }

    public function test_returns_zero_for_efectivo(): void
    {
        $this->assertSame(0.0, $this->calculator->calculate(PaymentMethod::Efectivo, 5000.00));
    }

    public function test_returns_zero_for_transferencia(): void
    {
        $this->assertSame(0.0, $this->calculator->calculate(PaymentMethod::Transferencia, 5000.00));
    }

    public function test_returns_zero_for_zero_amount(): void
    {
        $this->assertSame(0.0, $this->calculator->calculate(PaymentMethod::TarjetaCredito, 0.0));
    }

    public function test_throws_for_negative_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->calculate(PaymentMethod::TarjetaCredito, -100.00);
    }

    public function test_rounds_to_two_decimals(): void
    {
        // 333.33 × 0.0340 = 11.33322 → debería redondear a 11.33
        $fee = $this->calculator->calculate(PaymentMethod::TarjetaCredito, 333.33);
        $this->assertSame(11.33, $fee);
    }

    public function test_handles_amounts_with_cents(): void
    {
        // 1234.56 × 0.0340 = 41.97504 → 41.98
        $fee = $this->calculator->calculate(PaymentMethod::TarjetaCredito, 1234.56);
        $this->assertSame(41.98, $fee);
    }

    // ─── rateFor() ───────────────────────────────────────────

    public function test_rate_for_credit_returns_configured_rate(): void
    {
        $this->assertSame(0.0340, $this->calculator->rateFor(PaymentMethod::TarjetaCredito));
    }

    public function test_rate_for_debit_returns_configured_rate(): void
    {
        $this->assertSame(0.0250, $this->calculator->rateFor(PaymentMethod::TarjetaDebito));
    }

    public function test_rate_for_efectivo_returns_zero(): void
    {
        $this->assertSame(0.0, $this->calculator->rateFor(PaymentMethod::Efectivo));
    }
}
