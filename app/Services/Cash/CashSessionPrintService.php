<?php

declare(strict_types=1);

namespace App\Services\Cash;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\CashSession;
use App\Models\CompanySetting;

/**
 * Prepara los datos de una sesión de caja para la hoja de cierre imprimible.
 *
 * Mismo patrón que InvoicePrintService (SRP): orquesta carga eager, formato y
 * agregaciones delegadas a CashBalanceCalculator para que la Blade
 * resources/views/cash-sessions/print.blade.php sea puramente declarativa.
 *
 * NO calcula saldos: delega en CashBalanceCalculator (una sola fuente de verdad).
 * NO persiste nada: solo lee.
 * NO renderiza HTML: eso lo hace la Blade.
 *
 * La hoja de cierre es el documento que el cajero firma (y el autorizador,
 * si hubo descuadre) como prueba física del cuadre. Por eso incluye:
 *   - Bloque de empresa/sucursal como cabecera legal.
 *   - Totales desglosados por método de pago y por categoría de gasto.
 *   - Kardex cronológico completo de la sesión.
 *   - Espacio para firmas manuales en la Blade.
 */
class CashSessionPrintService
{
    public function __construct(
        private readonly CashBalanceCalculator $calculator,
    ) {}

    /**
     * Retorna el payload completo para la vista de impresión del cierre.
     *
     * @return array<string, mixed>
     */
    public function buildPrintPayload(CashSession $session): array
    {
        // Eager load de todas las relaciones que la vista consume. loadMissing
        // es idempotente: si ya están cargadas no re-queries.
        $session->loadMissing([
            'establishment.companySetting',
            'openedBy',
            'closedBy',
            'authorizedBy',
            'movements' => fn ($q) => $q->orderBy('occurred_at')->orderBy('id'),
            'movements.user',
        ]);

        $company = $session->establishment?->companySetting
            ?? CompanySetting::current();

        return [
            'session'        => $session,
            'isOpen'         => $session->isOpen(),
            'company'        => $this->buildCompanyBlock($company),
            'establishment'  => $this->buildEstablishmentBlock($session),
            'people'         => $this->buildPeopleBlock($session),
            'dates'          => $this->buildDatesBlock($session),
            'balances'       => $this->buildBalancesBlock($session),
            'cashFlow'       => $this->buildCashFlowBlock($session),
            'byPaymentMethod' => $this->buildPaymentMethodBlock($session),
            'byExpenseCategory' => $this->buildExpenseCategoryBlock($session),
            'movements'      => $this->mapMovementsForView($session),
            'meta'           => $this->buildMetaBlock(),
        ];
    }

    // ─── Bloques de contenido ────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function buildCompanyBlock(?CompanySetting $company): array
    {
        if ($company === null) {
            return [
                'name'    => 'Empresa',
                'rtn'     => '',
                'address' => '',
                'phone'   => '',
                'email'   => '',
            ];
        }

        return [
            'name'    => (string) ($company->trade_name ?: $company->legal_name),
            'rtn'     => (string) ($company->formatted_rtn ?? $company->rtn),
            'address' => (string) ($company->full_address ?? $company->address),
            'phone'   => (string) $company->phone,
            'email'   => (string) $company->email,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function buildEstablishmentBlock(CashSession $session): array
    {
        $est = $session->establishment;

        return [
            'name'    => $est?->name ?? '—',
            'code'    => $est?->code ?? '—',
            'address' => $est?->address,
            'city'    => $est?->city,
            'phone'   => $est?->phone,
            'is_main' => (bool) $est?->is_main,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function buildPeopleBlock(CashSession $session): array
    {
        return [
            'opened_by'     => $session->openedBy?->name ?? '—',
            'closed_by'     => $session->closedBy?->name,
            'authorized_by' => $session->authorizedBy?->name,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function buildDatesBlock(CashSession $session): array
    {
        return [
            'opened_at'       => $session->opened_at?->format('d/m/Y H:i'),
            'closed_at'       => $session->closed_at?->format('d/m/Y H:i'),
            'duration_human'  => $this->formatDuration($session),
        ];
    }

    /**
     * Saldos: ya están persistidos como snapshots en la sesión.
     *
     * Se exponen tanto formateados (para mostrar) como en float (para el
     * swing/badge de descuadre en la Blade: clase CSS según signo).
     *
     * @return array<string, mixed>
     */
    private function buildBalancesBlock(CashSession $session): array
    {
        $opening    = (float) $session->opening_amount;
        $expected   = (float) ($session->expected_closing_amount ?? $this->calculator->expectedCash($session));
        $actual     = $session->actual_closing_amount !== null
            ? (float) $session->actual_closing_amount
            : null;
        $discrepancy = $session->discrepancy !== null
            ? (float) $session->discrepancy
            : null;

        return [
            'opening'           => $this->formatMoney($opening),
            'expected'          => $this->formatMoney($expected),
            'actual'            => $actual !== null ? $this->formatMoney($actual) : null,
            'discrepancy'       => $discrepancy !== null ? $this->formatMoney($discrepancy) : null,
            'discrepancy_raw'   => $discrepancy,
            'discrepancy_sign'  => $this->classifyDiscrepancy($discrepancy),
            'tolerance'         => $this->formatMoney(
                $session->establishment?->companySetting?->effectiveCashDiscrepancyTolerance() ?? 0.0
            ),
        ];
    }

    /**
     * Flujo de efectivo agregado (solo payment_method = efectivo).
     *
     * @return array<string, string>
     */
    private function buildCashFlowBlock(CashSession $session): array
    {
        return [
            'inflows'  => $this->formatMoney($this->calculator->totalCashInflows($session)),
            'outflows' => $this->formatMoney($this->calculator->totalCashOutflows($session)),
        ];
    }

    /**
     * Totales de venta por método de pago (incluye no-efectivo).
     *
     * @return array<int, array{method: string, label: string, amount: string, amount_raw: float}>
     */
    private function buildPaymentMethodBlock(CashSession $session): array
    {
        $raw = $this->calculator->totalsByPaymentMethod($session);

        $rows = [];
        foreach ($raw as $methodValue => $amount) {
            $method = PaymentMethod::tryFrom($methodValue);
            $rows[] = [
                'method'     => $methodValue,
                'label'      => $method?->getLabel() ?? ucfirst((string) $methodValue),
                'amount'     => $this->formatMoney((float) $amount),
                'amount_raw' => (float) $amount,
            ];
        }

        // Ordenar por monto descendente — en el cierre el cajero quiere ver
        // primero el método con mayor volumen del día.
        usort($rows, fn ($a, $b) => $b['amount_raw'] <=> $a['amount_raw']);

        return $rows;
    }

    /**
     * Totales de gastos de caja chica por categoría.
     *
     * @return array<int, array{category: string, label: string, amount: string, amount_raw: float}>
     */
    private function buildExpenseCategoryBlock(CashSession $session): array
    {
        $raw = $this->calculator->totalsByExpenseCategory($session);

        $rows = [];
        foreach ($raw as $categoryValue => $amount) {
            $category = ExpenseCategory::tryFrom($categoryValue);
            $rows[] = [
                'category'   => $categoryValue,
                'label'      => $category?->getLabel() ?? ucfirst((string) $categoryValue),
                'amount'     => $this->formatMoney((float) $amount),
                'amount_raw' => (float) $amount,
            ];
        }

        usort($rows, fn ($a, $b) => $b['amount_raw'] <=> $a['amount_raw']);

        return $rows;
    }

    /**
     * Kardex cronológico completo de la sesión (para el detalle en la hoja).
     *
     * @return array<int, array<string, mixed>>
     */
    private function mapMovementsForView(CashSession $session): array
    {
        if ($session->movements->isEmpty()) {
            return [];
        }

        return $session->movements->map(function ($movement) {
            $type = $movement->type;
            $method = $movement->payment_method;
            $category = $movement->category;

            return [
                'occurred_at'    => $movement->occurred_at?->format('d/m/Y H:i'),
                'type'           => $type?->value,
                'type_label'     => $type?->getLabel() ?? '—',
                'method'         => $method?->value,
                'method_label'   => $method?->getLabel() ?? '—',
                'category_label' => $category?->getLabel(),
                'amount'         => $this->formatMoney((float) $movement->amount),
                'amount_raw'     => (float) $movement->amount,
                'is_inflow'      => $type?->isInflow() ?? false,
                'is_outflow'     => $type?->isOutflow() ?? false,
                'description'    => $movement->description,
                'user_name'      => $movement->user?->name ?? '—',
            ];
        })->toArray();
    }

    /**
     * Metadatos de la impresión (no es registro fiscal — no requiere snapshot).
     *
     * @return array<string, string>
     */
    private function buildMetaBlock(): array
    {
        return [
            'printed_at'   => now()->format('d/m/Y H:i'),
            'printed_by'   => auth()->user()?->name ?? '—',
        ];
    }

    // ─── Helpers de formato ──────────────────────────────────

    /**
     * Formato de moneda: "1,234.56" sin símbolo (L va en la vista).
     */
    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, '.', ',');
    }

    /**
     * Duración legible entre apertura y cierre (o hasta ahora si sigue abierta).
     */
    private function formatDuration(CashSession $session): ?string
    {
        if (! $session->opened_at) {
            return null;
        }

        $end = $session->closed_at ?? now();
        $diff = $session->opened_at->diff($end);

        $parts = [];
        if ($diff->h > 0) {
            $parts[] = $diff->h . 'h';
        }
        $parts[] = $diff->i . 'min';

        return implode(' ', $parts);
    }

    /**
     * Clasifica el descuadre para que la Blade aplique color/badge sin lógica.
     * Devuelve 'exact' (0), 'positive' (sobra), 'negative' (falta), 'pending' (no cerrada).
     */
    private function classifyDiscrepancy(?float $discrepancy): string
    {
        if ($discrepancy === null) {
            return 'pending';
        }

        if ($discrepancy > 0) {
            return 'positive';
        }

        if ($discrepancy < 0) {
            return 'negative';
        }

        return 'exact';
    }
}
