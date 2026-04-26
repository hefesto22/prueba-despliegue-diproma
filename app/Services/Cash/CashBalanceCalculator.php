<?php

namespace App\Services\Cash;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Models\CashMovement;
use App\Models\CashSession;

/**
 * Calculadora pura del saldo esperado de caja al cierre.
 *
 * Sin efectos secundarios: solo lee movimientos y devuelve cifras. Cualquier
 * persistencia (actualizar `expected_closing_amount` en la sesión) la hace
 * `CashSessionService`. SRP a rajatabla.
 *
 * Fórmula:
 *   expected_cash = opening_amount
 *                 + Σ ingresos en efectivo (sale_income, opening_balance manual)
 *                 − Σ egresos en efectivo (expense, supplier_payment, deposit)
 *                 ± ajustes en efectivo (signo según el contexto del adjustment)
 *
 * IMPORTANTE: solo `payment_method = efectivo` afecta el saldo físico. Los
 * movimientos en tarjeta/transferencia/cheque se ignoran para este cálculo
 * (existen para reportes y cuadre por método, pero no entran al cajón).
 *
 * Por qué no centralizar el signo en una sola pasada con `signedAmount`:
 *   - Mantener inflows / outflows separados permite emitir reportes detallados
 *     ("ingresaron L. X, salieron L. Y") sin recalcular.
 *   - El consumidor pide la cifra que necesita (expectedCash, totalInflows,
 *     totalOutflowsByCategory, etc.).
 */
class CashBalanceCalculator
{
    /**
     * Saldo en efectivo que debería haber en caja al cerrar la sesión.
     *
     * Suma sobre TODOS los movimientos de la sesión (no solo los pre-cargados).
     * Si la sesión ya cargó la relación `movements`, se reutiliza para evitar
     * query extra; si no, se carga.
     */
    public function expectedCash(CashSession $session): float
    {
        $opening = (float) $session->opening_amount;
        // Accessor `$session->movements` lazy-loadea en la 1ra llamada y cachea
        // para las siguientes (popula `relationLoaded`). Antes esta función
        // usaba `->movements()->get()` que NO cachea — cada llamada repetía la
        // query. Para infolists con varios entries que invocan al calculator,
        // eso es N queries innecesarias por render.
        $movements = $session->movements;

        $inflows = 0.0;
        $outflows = 0.0;

        foreach ($movements as $movement) {
            // Solo movimientos en efectivo afectan el saldo físico.
            if (! $movement->affectsCashBalance()) {
                continue;
            }

            // opening_balance manual NO se suma porque opening_amount ya está
            // contabilizado al inicio de la fórmula. Lo mismo con closing_balance:
            // es solo registro, no afecta el cálculo de "qué esperamos tener".
            if ($movement->type === CashMovementType::OpeningBalance
                || $movement->type === CashMovementType::ClosingBalance
            ) {
                continue;
            }

            if ($movement->type->isInflow()) {
                $inflows += (float) $movement->amount;
                continue;
            }

            if ($movement->type->isOutflow()) {
                $outflows += (float) $movement->amount;
                continue;
            }

            // Adjustment: ajuste manual del cajero (ej. corregir un faltante
            // menor al cierre). Se suma como inflow por default porque el
            // caso real mayoritario es reponer. Si en el futuro aparece una
            // necesidad de "ajuste negativo" con frecuencia suficiente, se
            // resuelve creando un subtipo dedicado — mismo patrón que
            // SaleCancellation — en vez de sobrecargar Adjustment con un
            // campo `direction`.
            $inflows += (float) $movement->amount;
        }

        return round($opening + $inflows - $outflows, 2);
    }

    /**
     * Total de ingresos en efectivo de la sesión (excluye opening_balance).
     */
    public function totalCashInflows(CashSession $session): float
    {
        return $this->sumByPredicate(
            $session,
            fn (CashMovement $m) => $m->affectsCashBalance()
                && $m->type !== CashMovementType::OpeningBalance
                && $m->type !== CashMovementType::ClosingBalance
                && $m->type->isInflow(),
        );
    }

    /**
     * Total de egresos en efectivo de la sesión.
     */
    public function totalCashOutflows(CashSession $session): float
    {
        return $this->sumByPredicate(
            $session,
            fn (CashMovement $m) => $m->affectsCashBalance() && $m->type->isOutflow(),
        );
    }

    /**
     * Total cobrado por método de pago (incluye no-efectivo).
     *
     * Útil para el cuadre por método al cierre: "vendiste L. 5,000 en tarjeta,
     * L. 2,000 en transferencia, L. 8,000 en efectivo".
     *
     * @return array<string, float>  Map [payment_method => total]
     */
    public function totalsByPaymentMethod(CashSession $session): array
    {
        $movements = $session->movements; // accessor: lazy load + caché

        $totals = [];

        foreach ($movements as $movement) {
            if ($movement->type !== CashMovementType::SaleIncome) {
                continue;
            }

            $key = $movement->payment_method->value;
            $totals[$key] = ($totals[$key] ?? 0.0) + (float) $movement->amount;
        }

        // Normalizar a 2 decimales.
        return array_map(fn (float $v) => round($v, 2), $totals);
    }

    /**
     * Total de gastos de caja chica agrupados por categoría.
     *
     * Útil para el PDF de cierre: "Combustible L. 200, Papelería L. 50,
     * Mantenimiento L. 120". Permite detectar anomalías mensuales al agregar
     * varios cierres.
     *
     * Alcance deliberadamente estrecho:
     *   - Solo `type = Expense` (no SupplierPayment ni Deposit — esos tienen
     *     sus propios reportes).
     *   - Solo `payment_method = Efectivo` — las categorías existen para
     *     trackear caja chica, que por diseño es flujo de efectivo (ver
     *     RecordExpenseAction). Si aparece algún Expense no-efectivo (caso
     *     teórico hoy, no soportado por el UI), se ignora para no contaminar
     *     el reporte del cierre con gastos que no afectaron el cajón.
     *
     * Los movimientos sin `category` (no debería pasar por validación, pero
     * defensivamente) se agrupan bajo `ExpenseCategory::Otros`.
     *
     * @return array<string, float>  Map [expense_category => total]
     */
    public function totalsByExpenseCategory(CashSession $session): array
    {
        $movements = $session->movements; // accessor: lazy load + caché

        $totals = [];

        foreach ($movements as $movement) {
            if ($movement->type !== CashMovementType::Expense) {
                continue;
            }

            if ($movement->payment_method !== PaymentMethod::Efectivo) {
                continue;
            }

            $key = $movement->category?->value ?? \App\Enums\ExpenseCategory::Otros->value;
            $totals[$key] = ($totals[$key] ?? 0.0) + (float) $movement->amount;
        }

        // Normalizar a 2 decimales.
        return array_map(fn (float $v) => round($v, 2), $totals);
    }

    /**
     * Discrepancia = actual_closing - expected_closing.
     *
     * Positivo: sobra dinero. Negativo: falta dinero. Cero: cuadre exacto.
     */
    public function discrepancy(float $actualClosingAmount, float $expectedClosingAmount): float
    {
        return round($actualClosingAmount - $expectedClosingAmount, 2);
    }

    /**
     * @param  callable(CashMovement): bool  $predicate
     */
    private function sumByPredicate(CashSession $session, callable $predicate): float
    {
        $movements = $session->movements; // accessor: lazy load + caché

        $total = 0.0;
        foreach ($movements as $movement) {
            if ($predicate($movement)) {
                $total += (float) $movement->amount;
            }
        }

        return round($total, 2);
    }
}
