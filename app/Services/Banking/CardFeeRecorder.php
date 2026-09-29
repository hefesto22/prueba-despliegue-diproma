<?php

namespace App\Services\Banking;

use App\Enums\PaymentMethod;
use App\Models\Sale;

/**
 * Registra la comisión del procesador cuando una venta se cobra con tarjeta.
 *
 * Desde el rediseño "todo en Compras" (2026-09-28) la comisión se guarda en
 * la propia venta (`sales.card_fee_amount`) en lugar de generar un Gasto:
 *   - No es una compra a un proveedor ni sale del cajón: el banco la retiene
 *     del depósito.
 *   - Generar un registro por cada venta con tarjeta llenaría Compras de
 *     comisiones automáticas.
 * La Utilidad Neta y el Reporte Mensual de Gastos la leen de las ventas.
 *
 * Por qué un service separado de SaleService/RepairDeliveryService:
 *   - SRP: ninguno de los dos tiene por qué conocer tasas ni redondeos de la
 *     comisión; los dos consumen la misma lógica por un solo punto.
 *
 * Atomicidad:
 *   Se invoca DENTRO de la transacción del caller (SaleService::processSale,
 *   RepairDeliveryService::deliver). Si esa transacción hace rollback, la
 *   comisión también.
 */
class CardFeeRecorder
{
    public function __construct(
        private readonly CardFeeCalculator $calculator,
    ) {}

    /**
     * Guardar en la venta la comisión que corresponde a su cobro con tarjeta.
     *
     * No-op si el método de pago no es tarjeta o si el monto cobrado es 0.
     *
     * Por qué `chargedAmount` es opcional:
     *   - POS retail: el cliente paga el total con tarjeta; se usa `$sale->total`.
     *   - Reparaciones: el anticipo se cobra antes y solo el SALDO se cobra al
     *     entregar (puede ser con tarjeta). El banco cobra comisión solo sobre
     *     ese saldo, así que el caller pasa `chargedAmount: $outstanding`.
     *
     * @return float|null Comisión registrada, o null si no aplica.
     */
    public function recordIfApplicable(
        Sale $sale,
        PaymentMethod $method,
        ?float $chargedAmount = null,
    ): ?float {
        if (! $this->calculator->appliesTo($method)) {
            return null;
        }

        $totalAmount = $chargedAmount ?? (float) $sale->total;

        if ($totalAmount <= 0) {
            return null;
        }

        $feeAmount = $this->calculator->calculate($method, $totalAmount);

        if ($feeAmount === 0.0) {
            return null;
        }

        $sale->update(['card_fee_amount' => $feeAmount]);

        return $feeAmount;
    }
}
