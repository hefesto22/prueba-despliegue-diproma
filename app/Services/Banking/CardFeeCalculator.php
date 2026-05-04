<?php

namespace App\Services\Banking;

use App\Enums\PaymentMethod;
use App\Models\CompanySetting;

/**
 * Calculador de comisiones bancarias por pago con tarjeta.
 *
 * Responsabilidad única: dado un método de pago y un monto bruto, decidir si
 * aplica comisión y calcular el monto absoluto en lempiras. NO crea registros,
 * NO afecta caja — pura matemática.
 *
 * Por qué una clase aparte y no un static helper:
 *   - DI / testabilidad: el calculator depende de CompanySetting; con DI
 *     podemos inyectar un settings stub en tests sin tocar la BD ni el cache.
 *   - SRP: la decisión "qué tasa aplica a qué método" puede crecer (ej: por
 *     banco, por país, por tipo de cliente). Aislar la lógica permite extender
 *     sin tocar los services consumidores (Sale/Repair).
 *
 * Por qué inyectamos via callable y no la instancia singleton:
 *   - CompanySetting::current() es un singleton cacheado por 24h. Inyectar un
 *     callable que la resuelva nos permite refrescar dinámicamente en tests
 *     que muten settings sin invalidar el cache.
 */
class CardFeeCalculator
{
    /**
     * @param  \Closure(): CompanySetting  $settingsResolver
     *         Resolvedor de settings — se invoca cada vez que se necesita la
     *         tasa, para reflejar cambios en runtime sin reiniciar el container.
     */
    public function __construct(
        private readonly \Closure $settingsResolver,
    ) {}

    /**
     * ¿Este método de pago genera comisión bancaria?
     *
     * Solo crédito y débito. Efectivo, transferencia y cheque no pagan
     * comisión al procesador (en transferencia el banco puede cobrar fee
     * fijo pero NO porcentual sobre el monto — out of scope acá).
     */
    public function appliesTo(PaymentMethod $method): bool
    {
        return match ($method) {
            PaymentMethod::TarjetaCredito,
            PaymentMethod::TarjetaDebito => true,
            default => false,
        };
    }

    /**
     * Calcular el monto de la comisión bancaria sobre un total bruto.
     *
     * Base de cálculo: el TOTAL bruto cobrado al cliente (incluyendo ISV).
     * Es así porque el banco/procesador cobra sobre el monto que efectivamente
     * autorizó al comercio — no sobre el subtotal ni la utilidad.
     *
     * @param  float  $totalAmount  Total bruto en lempiras (el monto que el
     *                              cliente pagó con tarjeta, IVA incluido).
     * @return float  Comisión en lempiras, redondeada a 2 decimales. Cero si
     *                el método no aplica o el total es <= 0.
     *
     * @throws \InvalidArgumentException Si el total es negativo (programmer error).
     */
    public function calculate(PaymentMethod $method, float $totalAmount): float
    {
        if ($totalAmount < 0) {
            throw new \InvalidArgumentException(
                "CardFeeCalculator: totalAmount no puede ser negativo. Recibido: {$totalAmount}"
            );
        }

        if (! $this->appliesTo($method) || $totalAmount === 0.0) {
            return 0.0;
        }

        $rate = $this->rateFor($method);

        return round($totalAmount * $rate, 2);
    }

    /**
     * Tasa efectiva (decimal, ej. 0.0340 para 3.4%) para un método de pago.
     *
     * Public porque el UI puede querer mostrar la tasa actual (ej. "el banco
     * te cobrará 3.4% de esta venta") sin recalcular el monto.
     */
    public function rateFor(PaymentMethod $method): float
    {
        $settings = ($this->settingsResolver)();

        return match ($method) {
            PaymentMethod::TarjetaCredito => $settings->effectiveCardFeeRateCredit(),
            PaymentMethod::TarjetaDebito => $settings->effectiveCardFeeRateDebit(),
            default => 0.0,
        };
    }
}
