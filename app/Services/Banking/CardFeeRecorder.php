<?php

namespace App\Services\Banking;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Models\Sale;
use Illuminate\Support\Facades\Auth;

/**
 * Registra el Expense automático cuando una venta se cobra con tarjeta.
 *
 * Responsabilidad: dado un Sale ya persistido y su PaymentMethod, decidir si
 * corresponde generar un gasto por comisión bancaria — y si sí, persistirlo
 * con todos los datos correctos (categoría, descripción, vínculo a la venta,
 * usuario, sucursal).
 *
 * Por qué un service separado de SaleService/RepairDeliveryService:
 *   - SRP: SaleService orquesta la venta. RepairDeliveryService orquesta la
 *     entrega. Ninguno tiene por qué conocer el detalle de cómo se registra
 *     un gasto bancario. Si mañana cambia (ej. enviar a un libro contable
 *     externo), solo cambia este recorder.
 *   - DRY: ambos services consumen la misma lógica vía un solo punto.
 *
 * Por qué NO usa ExpenseService::register:
 *   - ExpenseService se acopla a CashSessionService para crear CashMovements
 *     cuando el gasto es en efectivo. Las comisiones por tarjeta NO afectan
 *     caja (el banco descuenta del depósito, no del cajón) — y no queremos
 *     introducir un acoplamiento innecesario solo para evitar el efectivo.
 *   - El Expense aquí se crea con payment_method = mismo de la venta
 *     (TarjetaCredito o TarjetaDebito), que `affectsCashBalance()` retorna
 *     false → ningún CashMovement se crearía igual. Pero queda explícito.
 *
 * Atomicidad:
 *   El método `recordIfApplicable()` se invoca DENTRO de la transacción del
 *   caller (SaleService::processSale, RepairDeliveryService::deliver). Si la
 *   transacción hace rollback, el Expense también — sin código adicional.
 */
class CardFeeRecorder
{
    public function __construct(
        private readonly CardFeeCalculator $calculator,
    ) {}

    /**
     * Crear el Expense de comisión si aplica al método de pago.
     *
     * No-op si el método de pago no es tarjeta o si el monto cobrado es 0.
     * El caller debe invocar este método DENTRO de su propia DB::transaction()
     * para garantizar atomicidad con la venta.
     *
     * Por qué `chargedAmount` es opcional:
     *   - POS retail: el cliente paga el total completo con tarjeta, así que
     *     el caller puede omitirlo y se usa `$sale->total`.
     *   - Reparaciones: el anticipo se cobra antes (siempre en efectivo) y
     *     solo el SALDO restante se cobra al entregar (puede ser tarjeta).
     *     En ese caso el banco solo cobra comisión sobre el saldo pasado al
     *     POS — no sobre el total de la venta. El caller pasa
     *     `chargedAmount: $outstanding` para que el cálculo refleje la
     *     realidad bancaria.
     *
     * @param  Sale  $sale  Venta ya persistida.
     * @param  PaymentMethod  $method  Método de pago efectivamente usado para
     *                                  pasar la tarjeta por el POS bancario.
     * @param  float|null  $chargedAmount  Monto que efectivamente pasó por la
     *                                      tarjeta. Null = usar `$sale->total`.
     * @return Expense|null  El Expense creado, o null si no aplicaba comisión.
     */
    public function recordIfApplicable(
        Sale $sale,
        PaymentMethod $method,
        ?float $chargedAmount = null,
    ): ?Expense {
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

        $rate = $this->calculator->rateFor($method);
        $ratePercent = number_format($rate * 100, 2);

        $userId = Auth::id() ?? $sale->created_by;

        if ($userId === null) {
            // Defensa en profundidad: no debería pasar en flujo normal —
            // todas las ventas se procesan con usuario autenticado o
            // created_by setteado. Si pasa, fail-fast con mensaje claro.
            throw new \RuntimeException(
                "CardFeeRecorder: no se puede registrar la comisión bancaria sin usuario " .
                "(sale_id={$sale->id}). Se requiere auth()->id() o sale->created_by."
            );
        }

        return Expense::create([
            'establishment_id' => $sale->establishment_id,
            'user_id' => $userId,
            'sale_id' => $sale->id,
            // Misma fecha que la venta — la comisión es contemporánea al cobro.
            // Usamos sale->date (no now()) para alinear con el período fiscal
            // correcto si la venta se procesó en otra fecha de la actual.
            'expense_date' => $sale->date,
            'category' => ExpenseCategory::ComisionesBancarias->value,
            // Mismo método que la venta — refleja la realidad: la comisión
            // vino del cobro con esta tarjeta. Como TarjetaCredito/TarjetaDebito
            // tienen affectsCashBalance() = false, NO se creará CashMovement.
            'payment_method' => $method->value,
            'amount_total' => $feeAmount,
            // ISV: no marcamos isv_amount aunque las comisiones bancarias en HN
            // técnicamente llevan ISV — el banco emite su propia factura
            // mensual y el contador la cargará por separado como deducible.
            // Acá registramos el costo financiero, no la factura del banco.
            'isv_amount' => null,
            'is_isv_deductible' => false,
            'description' => sprintf(
                'Comisión bancaria por pago con %s en venta %s (%s%% sobre L %s)',
                $method->getLabel(),
                $sale->sale_number ?? "#{$sale->id}",
                $ratePercent,
                number_format($totalAmount, 2),
            ),
            'created_by' => $userId,
        ]);
    }
}
