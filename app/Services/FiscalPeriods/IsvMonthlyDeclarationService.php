<?php

namespace App\Services\FiscalPeriods;

use App\Models\FiscalPeriod;
use App\Models\IsvMonthlyDeclaration;
use App\Models\IsvRetentionReceived;
use App\Models\User;
use App\Services\FiscalBooks\PurchaseBookService;
use App\Services\FiscalBooks\SalesBookService;
use App\Services\FiscalPeriods\Exceptions\DeclaracionIsvYaExisteException;
use App\Services\FiscalPeriods\Exceptions\PeriodoFiscalNoReabiertoException;
use App\Services\FiscalPeriods\Exceptions\SnapshotActivoNoExisteException;
use App\Services\FiscalPeriods\ValueObjects\IsvDeclarationTotals;
use Illuminate\Support\Facades\DB;

/**
 * Service que orquesta la creación de snapshots de declaración ISV mensual
 * (Formulario 201 SAR) y su ciclo de rectificativas.
 *
 * Responsabilidades (en orden de criticidad):
 *   1. Calcular los 12 totales del Formulario 201 desde la verdad operativa
 *      (libros SAR + retenciones del período + saldo arrastrado del mes previo).
 *   2. Persistir snapshots inmutables en `isv_monthly_declarations`.
 *   3. Cerrar el período fiscal en sincronía con la creación del snapshot
 *      (delega en `FiscalPeriodService::declare`).
 *   4. Manejar el ciclo de rectificativa: marcar el snapshot vigente como
 *      `superseded_at` y crear el nuevo activo, dentro de una sola transacción.
 *
 * Separación CQRS-light:
 *   - `computeTotalsFor()`  → QUERY pura: recalcula desde libros, no toca DB de
 *     escritura. Apto para preview en Filament Page sin efectos secundarios.
 *   - `declare()`           → COMMAND: primer snapshot del período. Falla si ya
 *     existe uno activo (DeclaracionIsvYaExisteException).
 *   - `redeclare()`         → COMMAND: rectificativa. Falla si el período no
 *     fue reabierto (PeriodoFiscalNoReabiertoException) o si no hay snapshot
 *     activo previo (SnapshotActivoNoExisteException).
 *
 * Política de saldo arrastrado:
 *   El `saldo_a_favor_anterior` se lee del snapshot activo del MES PREVIO. Si
 *   ese snapshot no existe (mes inicial del tracking, o mes previo aún no
 *   declarado) se usa 0.0. NO se hace cascada automática hacia adelante: si el
 *   contador rectifica un mes pasado, los meses posteriores deben rectificarse
 *   manualmente — la cascada automática es compleja y puede contradecir la
 *   intención del contador (ej: el SAR ya aceptó la declaración de meses
 *   posteriores con el saldo viejo). Documentado en PHPDoc del método.
 *
 * Atomicidad:
 *   Tanto `declare()` como `redeclare()` ejecutan toda su lógica dentro de
 *   `DB::transaction` con `lockForUpdate` sobre el FiscalPeriod. Si la
 *   creación del snapshot o el cierre del período fallan, todo se revierte —
 *   nunca queda un snapshot huérfano sin período cerrado, ni un período
 *   cerrado sin su snapshot.
 */
class IsvMonthlyDeclarationService
{
    public function __construct(
        private readonly FiscalPeriodService $fiscalPeriodService,
        private readonly SalesBookService $salesBookService,
        private readonly PurchaseBookService $purchaseBookService,
    ) {}

    // ─── QUERY pura ──────────────────────────────────────────

    /**
     * Calcula los 12 totales del Formulario 201 SAR para un período fiscal.
     *
     * Sin efectos secundarios — apto para preview en Filament Page antes de
     * confirmar la presentación al SIISAR. La consistencia con el snapshot que
     * se persistirá luego está garantizada porque `declare()` y `redeclare()`
     * llaman a este mismo método dentro de su transacción.
     *
     * Fuentes de datos (single source of truth — coinciden con los Excel SAR):
     *   - Ventas/Compras: `SalesBookService::build` y `PurchaseBookService::build`
     *     (sin filtro de sucursal — la declaración SAR es a nivel empresa).
     *   - Retenciones: suma de `IsvRetentionReceived::amount` del período.
     *   - Saldo previo: `saldo_a_favor_siguiente` del snapshot activo del mes
     *     anterior. 0.0 si no existe (primer mes o no declarado aún).
     *
     * Mapeo Libros SAR → Formulario 201:
     *   - ventas_gravadas   = SalesBookSummary::gravadoNeto()
     *   - ventas_exentas    = SalesBookSummary::exentoNeto()
     *   - isv_debito_fiscal = SalesBookSummary::isvNeto()
     *   - compras_gravadas  = PurchaseBookSummary::gravadoNeto()
     *   - compras_exentas   = PurchaseBookSummary::exentoNeto()
     *   - isv_credito_fiscal = PurchaseBookSummary::creditoFiscalNeto()
     */
    public function computeTotalsFor(FiscalPeriod $period): IsvDeclarationTotals
    {
        $salesBook = $this->salesBookService->build(
            $period->period_year,
            $period->period_month,
        );

        $purchaseBook = $this->purchaseBookService->build(
            $period->period_year,
            $period->period_month,
        );

        $retencionesRecibidas = (float) IsvRetentionReceived::query()
            ->forPeriod($period->period_year, $period->period_month)
            ->sum('amount');

        $saldoAFavorAnterior = $this->resolveSaldoAFavorAnterior(
            $period->period_year,
            $period->period_month,
        );

        return IsvDeclarationTotals::calculate(
            ventasGravadas:          $salesBook->summary->gravadoNeto(),
            ventasExentas:           $salesBook->summary->exentoNeto(),
            comprasGravadas:         $purchaseBook->summary->gravadoNeto(),
            comprasExentas:          $purchaseBook->summary->exentoNeto(),
            isvDebitoFiscal:         $salesBook->summary->isvNeto(),
            isvCreditoFiscal:        $purchaseBook->summary->creditoFiscalNeto(),
            isvRetencionesRecibidas: $retencionesRecibidas,
            saldoAFavorAnterior:     $saldoAFavorAnterior,
        );
    }

    // ─── COMMANDS ────────────────────────────────────────────

    /**
     * Presenta la PRIMERA declaración ISV del período (no rectificativa).
     *
     * Flujo atómico:
     *   1. Lock del FiscalPeriod (lockForUpdate evita carreras con declare/reopen
     *      simultáneos en el mismo período).
     *   2. Verificar que NO exista ya un snapshot activo
     *      (DeclaracionIsvYaExisteException si existe — guía al contador a usar
     *      el flujo reopen + redeclare).
     *   3. Recalcular totales (computeTotalsFor) DENTRO de la transacción para
     *      capturar el estado real al momento del cierre.
     *   4. Insertar snapshot con superseded_at = NULL (activo).
     *   5. Cerrar el período fiscal (FiscalPeriodService::declare). Si falla
     *      por PeriodoFiscalYaDeclaradoException, todo se revierte.
     *
     * @throws DeclaracionIsvYaExisteException Si ya hay snapshot activo en este período.
     */
    public function declare(
        FiscalPeriod $period,
        User $declaredBy,
        ?string $siisarAcuse = null,
        ?string $notes = null,
    ): IsvMonthlyDeclaration {
        return DB::transaction(function () use ($period, $declaredBy, $siisarAcuse, $notes) {
            $fresh = FiscalPeriod::lockForUpdate()->findOrFail($period->id);

            $existingActive = IsvMonthlyDeclaration::query()
                ->forFiscalPeriod($fresh->id)
                ->active()
                ->first();

            if ($existingActive !== null) {
                throw new DeclaracionIsvYaExisteException(
                    periodYear: $fresh->period_year,
                    periodMonth: $fresh->period_month,
                    existingDeclarationId: $existingActive->id,
                );
            }

            $totals = $this->computeTotalsFor($fresh);

            $snapshot = IsvMonthlyDeclaration::create([
                'fiscal_period_id' => $fresh->id,
                'declared_at' => now(),
                'declared_by_user_id' => $declaredBy->id,
                'siisar_acuse_number' => $siisarAcuse,
                'notes' => $notes,
                ...$totals->toArray(),
            ]);

            // Cerrar el período al SAR. Si esto lanza
            // PeriodoFiscalYaDeclaradoException la transacción revierte el
            // snapshot recién creado — invariante: nunca un snapshot sin
            // período cerrado.
            $this->fiscalPeriodService->declare(
                period: $fresh,
                declaredBy: $declaredBy,
                notes: $notes,
            );

            return $snapshot;
        });
    }

    /**
     * Presenta una declaración RECTIFICATIVA (Acuerdo SAR 189-2014).
     *
     * Precondiciones (ambas obligatorias, fail-fast):
     *   1. El período debe haber sido reabierto previamente
     *      (FiscalPeriod::wasReopened()), capturando motivo y autoría vía
     *      FiscalPeriodService::reopen.
     *   2. Debe existir exactamente UN snapshot activo previo (la declaración
     *      original o la última rectificativa).
     *
     * Flujo atómico:
     *   1. Lock del FiscalPeriod.
     *   2. Verificar precondiciones (lanza excepciones tipadas).
     *   3. Recalcular totales (puede haber cambios desde la última declaración:
     *      facturas anuladas, retenciones nuevas, NCs, etc.).
     *   4. Marcar snapshot anterior como superseded_at = now() y
     *      superseded_by_user_id = $declaredBy. El UPDATE pasa por el Observer
     *      que valida que solo se tocan columnas mutables (whitelist).
     *   5. Crear NUEVO snapshot activo con los totales recalculados.
     *   6. Re-cerrar el período (FiscalPeriodService::declare actualiza
     *      declared_at > reopened_at, pasando el período a estado "cerrado").
     *
     * @throws PeriodoFiscalNoReabiertoException  Si el período no fue reabierto.
     * @throws SnapshotActivoNoExisteException    Si no hay snapshot activo previo.
     */
    public function redeclare(
        FiscalPeriod $period,
        User $declaredBy,
        ?string $siisarAcuse = null,
        ?string $notes = null,
    ): IsvMonthlyDeclaration {
        return DB::transaction(function () use ($period, $declaredBy, $siisarAcuse, $notes) {
            $fresh = FiscalPeriod::lockForUpdate()->findOrFail($period->id);

            if (! $fresh->wasReopened()) {
                throw new PeriodoFiscalNoReabiertoException(
                    periodYear: $fresh->period_year,
                    periodMonth: $fresh->period_month,
                );
            }

            $previousActive = IsvMonthlyDeclaration::query()
                ->forFiscalPeriod($fresh->id)
                ->active()
                ->first();

            if ($previousActive === null) {
                throw new SnapshotActivoNoExisteException(
                    periodYear: $fresh->period_year,
                    periodMonth: $fresh->period_month,
                );
            }

            $totals = $this->computeTotalsFor($fresh);

            // El Observer valida que solo se modifican columnas de la whitelist
            // (superseded_at, superseded_by_user_id). Si alguien intentara
            // tocar columnas fiscales desde acá, fallaría con RuntimeException.
            $previousActive->update([
                'superseded_at' => now(),
                'superseded_by_user_id' => $declaredBy->id,
            ]);

            $newSnapshot = IsvMonthlyDeclaration::create([
                'fiscal_period_id' => $fresh->id,
                'declared_at' => now(),
                'declared_by_user_id' => $declaredBy->id,
                'siisar_acuse_number' => $siisarAcuse,
                'notes' => $notes,
                ...$totals->toArray(),
            ]);

            // Cierra el período: declared_at se actualiza > reopened_at.
            // Como el período está abierto (reopened_at > declared_at previo),
            // FiscalPeriodService::declare NO lanzará PeriodoFiscalYaDeclaradoException.
            $this->fiscalPeriodService->declare(
                period: $fresh,
                declaredBy: $declaredBy,
                notes: $notes,
            );

            return $newSnapshot;
        });
    }

    // ─── Privados ────────────────────────────────────────────

    /**
     * Resuelve el saldo a favor arrastrado desde el mes inmediatamente anterior.
     *
     * Reglas:
     *   - Mes anterior se calcula con rollover de diciembre → enero del año
     *     anterior (ej: enero 2026 → diciembre 2025).
     *   - Si no existe FiscalPeriod para el mes anterior → 0.0 (caso típico:
     *     primer mes del tracking, ningún saldo histórico).
     *   - Si existe pero no tiene snapshot activo (período abierto, nunca
     *     declarado) → 0.0. El contador debe declarar primero los meses
     *     anteriores en orden cronológico para que el saldo fluya correctamente.
     *   - Si existe snapshot activo → leer su `saldo_a_favor_siguiente`.
     *
     * No-cascada: este método NO actualiza meses futuros si el contador
     * rectifica un mes pasado. La rectificativa de un mes anterior puede
     * cambiar su saldo siguiente, pero el snapshot del mes posterior queda
     * inmutable (es un snapshot histórico). Si el contador necesita propagar
     * el cambio debe rectificar manualmente cada mes posterior — la cascada
     * automática es compleja, sensible a fechas de aceptación SAR distintas
     * por mes, y puede sobrepasar la intención humana.
     */
    private function resolveSaldoAFavorAnterior(int $year, int $month): float
    {
        if ($month === 1) {
            $prevYear = $year - 1;
            $prevMonth = 12;
        } else {
            $prevYear = $year;
            $prevMonth = $month - 1;
        }

        $previousPeriod = FiscalPeriod::forMonth($prevYear, $prevMonth)->first();

        if ($previousPeriod === null) {
            return 0.0;
        }

        $previousSnapshot = IsvMonthlyDeclaration::query()
            ->forFiscalPeriod($previousPeriod->id)
            ->active()
            ->first();

        if ($previousSnapshot === null) {
            return 0.0;
        }

        return (float) $previousSnapshot->saldo_a_favor_siguiente;
    }
}
