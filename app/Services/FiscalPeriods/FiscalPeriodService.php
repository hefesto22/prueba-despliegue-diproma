<?php

namespace App\Services\FiscalPeriods;

use App\Models\CompanySetting;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\User;
use App\Services\FiscalPeriods\Exceptions\PeriodoFiscalCerradoException;
use App\Services\FiscalPeriods\Exceptions\PeriodoFiscalNoConfiguradoException;
use App\Services\FiscalPeriods\Exceptions\PeriodoFiscalYaDeclaradoException;
use App\Services\FiscalPeriods\Exceptions\PeriodoFiscalYaReabiertoException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lógica central de períodos fiscales.
 *
 * Responsabilidades (en orden de criticidad):
 *   1. Resolver el período fiscal que corresponde a una fecha/factura.
 *   2. Determinar si una factura se puede anular directamente o requiere NC.
 *   3. Registrar declaraciones ante SAR (cerrar períodos).
 *   4. Registrar reaperturas para declaraciones rectificativas.
 *
 * Reglas de dominio (Acuerdo SAR 481-2017 + 189-2014):
 *   - Una factura solo puede anularse si su período está ABIERTO.
 *   - Una factura con invoice_date < fiscal_period_start se considera
 *     pre-tracking (períodos previos ya declarados): NO se puede anular.
 *   - Reapertura solo aplica a períodos cerrados (declaración rectificativa).
 *
 * Separación de concerns:
 *   - Consultas (queries) → métodos que retornan datos, sin efectos secundarios.
 *   - Comandos (commands) → métodos void que modifican estado en transacción.
 */
class FiscalPeriodService
{
    /**
     * Resolver (o crear lazy) el período fiscal del mes actual.
     *
     * El lazy-create garantiza que siempre exista un registro para el mes
     * en curso cuando se emite una factura, sin requerir seed manual.
     */
    public function current(): FiscalPeriod
    {
        $this->assertConfigured();

        $now = CarbonImmutable::now();

        return $this->findOrCreateForMonth($now->year, $now->month);
    }

    /**
     * Resolver el período fiscal que corresponde a una factura.
     *
     * Usa invoice_date (fecha fiscal del documento), NO created_at: SAR exige
     * que la factura pertenezca al período de su fecha de emisión legal.
     *
     * @throws PeriodoFiscalNoConfiguradoException  Si fiscal_period_start no está seteado.
     */
    public function forInvoice(Invoice $invoice): FiscalPeriod
    {
        return $this->forDate($invoice->invoice_date);
    }

    /**
     * Resolver el período fiscal que corresponde a una fecha.
     *
     * Lazy-create: si el período de esa fecha aún no existe, se crea como
     * abierto. Esto permite declarar meses pasados sin seed manual.
     *
     * @throws PeriodoFiscalNoConfiguradoException  Si fiscal_period_start no está seteado.
     */
    public function forDate(CarbonInterface $date): FiscalPeriod
    {
        $this->assertConfigured();

        $d = CarbonImmutable::instance($date);

        return $this->findOrCreateForMonth($d->year, $d->month);
    }

    /**
     * ¿El período de un año/mes específico está abierto?
     *
     * Consulta pura: no crea el período si no existe. Un período inexistente
     * se considera abierto (aún no declarado).
     *
     * Ejecuta 1 query directa por llamada — apto para checks puntuales (gate
     * antes de declare/reopen, assertCanVoidInvoice). Para verificar una lista
     * de facturas en batch use `canVoidInvoice` que comparte un lookup map
     * memoizado por request.
     */
    public function isOpen(int $year, int $month): bool
    {
        $period = FiscalPeriod::forMonth($year, $month)->first();

        return $period === null || $period->isOpen();
    }

    /**
     * ¿Esta factura se puede anular directamente (sin NC)?
     *
     * Criterios acumulativos (todos deben cumplirse):
     *   1. La empresa tiene fiscal_period_start configurado.
     *   2. invoice_date >= fiscal_period_start (no es pre-tracking).
     *   3. El período de invoice_date está ABIERTO (nunca declarado o reabierto).
     *
     * Factura ya anulada devuelve false: no se "reanula" una anulación.
     *
     * Performance: el lookup del estado del período usa un map memoizado por
     * request (`loadFiscalPeriodsMap`). La primera llamada ejecuta UNA query
     * para traer todos los períodos existentes; las siguientes N-1 llamadas
     * (típicamente filas de un listado de facturas) leen el map en memoria.
     * Esto evita el N+1 que antes causaba 1 query por fila en `InvoicesTable`.
     */
    public function canVoidInvoice(Invoice $invoice): bool
    {
        if ($invoice->is_void) {
            return false;
        }

        $company = CompanySetting::current();

        if ($company->fiscal_period_start === null) {
            return false;
        }

        $invoiceDate = CarbonImmutable::instance($invoice->invoice_date);

        if ($invoiceDate->lessThan($company->fiscal_period_start)) {
            return false;
        }

        $period = $this->loadFiscalPeriodsMap()->get("{$invoiceDate->year}-{$invoiceDate->month}");

        // Período inexistente ≡ abierto (consistente con isOpen()).
        return $period === null || $period->isOpen();
    }

    /**
     * Gate imperativo: lanza excepción si la factura NO se puede anular.
     *
     * Úselo en el punto de entrada de la anulación (Filament action,
     * controlador, etc.) para fallar con mensaje útil en vez de silencioso.
     *
     * @throws PeriodoFiscalNoConfiguradoException Si fiscal_period_start es NULL.
     * @throws PeriodoFiscalCerradoException       Si el período ya fue declarado
     *                                             o la factura es pre-tracking.
     */
    public function assertCanVoidInvoice(Invoice $invoice): void
    {
        $company = CompanySetting::current();

        if ($company->fiscal_period_start === null) {
            throw new PeriodoFiscalNoConfiguradoException();
        }

        // Delego la doble regla (pre-tracking + período declarado) en el gate
        // genérico. El caso de facturas era el único callsite originalmente;
        // ahora ese mismo gate se reusa desde los Observers de Purchase e
        // IsvRetentionReceived, manteniendo una sola implementación del concepto
        // "fecha en período cerrado" (ISV.3a).
        $this->assertDateIsInOpenPeriod(
            $invoice->invoice_date,
            "la factura {$invoice->invoice_number}",
        );
    }

    /**
     * Gate genérico por AÑO/MES: lanza si el período fiscal está cerrado ante el SAR.
     *
     * Usado por Observers de documentos fiscales que capturan el período en
     * columnas directas (no derivado de una fecha) — ej: `IsvRetentionReceived`
     * con sus columnas `period_year` y `period_month`.
     *
     * Si el módulo no está activado (`fiscal_period_start` NULL) se considera
     * que no hay concepto de "período cerrado" y no se bloquea nada — default
     * allow, simétrico a `canVoidInvoice` que retorna false por default deny.
     * La asimetría tiene razón operativa: anular factura es una decisión del
     * usuario con UI explícita; editar una retención es un flujo normal que
     * no debe bloquearse si el módulo fiscal no está habilitado.
     *
     * @param  int     $year           Año del período.
     * @param  int     $month          Mes del período (1-12).
     * @param  string  $documentLabel  Descripción human-readable del documento
     *                                 (usada en el mensaje de la excepción).
     *
     * @throws PeriodoFiscalCerradoException Si el período ya fue declarado.
     */
    public function assertPeriodIsOpen(int $year, int $month, string $documentLabel): void
    {
        $company = CompanySetting::current();

        // Módulo no activado — no hay concepto de "período cerrado".
        if ($company->fiscal_period_start === null) {
            return;
        }

        if (! $this->isOpen($year, $month)) {
            throw new PeriodoFiscalCerradoException(
                periodYear: $year,
                periodMonth: $month,
                documentLabel: $documentLabel,
            );
        }
    }

    /**
     * Gate genérico por FECHA: lanza si la fecha cae en un período cerrado
     * (o es pre-tracking, anterior a fiscal_period_start).
     *
     * Usado por Observers de documentos fiscales cuyo período se deriva de
     * una columna de fecha — ej: `Purchase` con su columna `date`, `Invoice`
     * con `invoice_date`, etc.
     *
     * Aplica dos reglas acumulativas:
     *   1. Pre-tracking: fecha < fiscal_period_start → cerrado (ya se declaró
     *      antes de adoptar el sistema, es registro histórico intocable).
     *   2. Período de la fecha cerrado → bloqueo explícito.
     *
     * Módulo no activado → no bloquea (default allow, ver `assertPeriodIsOpen`).
     *
     * @throws PeriodoFiscalCerradoException Si la fecha cae en período cerrado
     *                                       o es pre-tracking.
     */
    public function assertDateIsInOpenPeriod(CarbonInterface $date, string $documentLabel): void
    {
        $company = CompanySetting::current();

        if ($company->fiscal_period_start === null) {
            return;
        }

        $d = CarbonImmutable::instance($date);

        // Pre-tracking: fechas anteriores a la adopción del sistema se tratan
        // como período cerrado (ya fueron declaradas sin nuestro tracking).
        if ($d->lessThan($company->fiscal_period_start)) {
            throw new PeriodoFiscalCerradoException(
                periodYear: $d->year,
                periodMonth: $d->month,
                documentLabel: $documentLabel,
            );
        }

        $this->assertPeriodIsOpen($d->year, $d->month, $documentLabel);
    }

    /**
     * Asegura que existan registros `FiscalPeriod` para todos los meses entre
     * `fiscal_period_start` y el mes anterior al actual (rango de meses vencidos).
     *
     * COMMAND — modifica estado, no retorna datos. Se invoca desde el scheduler
     * diario antes de las queries de lectura (badge, widget, alertas) para que
     * los meses sin actividad (ej: cero facturas en febrero) sigan apareciendo
     * en la lista de pendientes — el SAR exige declaración cero.
     *
     * Idempotente: usa `firstOrCreate` que es atómico por UNIQUE (year, month),
     * así que múltiples ejecuciones simultáneas no producen duplicados.
     *
     * Degradación silenciosa: si `fiscal_period_start` no está configurado el
     * comando termina sin hacer nada — consumidores que llaman este método
     * desde scheduler no necesitan envolverlo en try/catch.
     */
    public function ensureOverduePeriodsExist(): void
    {
        $company = CompanySetting::current();

        if ($company->fiscal_period_start === null) {
            return;
        }

        $start = CarbonImmutable::instance($company->fiscal_period_start)->startOfMonth();
        $currentMonthStart = CarbonImmutable::now()->startOfMonth();

        if ($start->greaterThanOrEqualTo($currentMonthStart)) {
            return;
        }

        for ($cursor = $start; $cursor->lessThan($currentMonthStart); $cursor = $cursor->addMonth()) {
            FiscalPeriod::firstOrCreate([
                'period_year' => $cursor->year,
                'period_month' => $cursor->month,
            ]);
        }
    }

    /**
     * Lista los períodos fiscales pendientes de declarar (solo meses vencidos).
     *
     * QUERY PURA — no modifica estado. Lee únicamente registros ya persistidos.
     * La población de meses sin actividad la hace `ensureOverduePeriodsExist()`,
     * típicamente desde el scheduler diario (ver routes/console.php).
     *
     * Un período se considera "pendiente" si:
     *   - Su inicio (día 1 del mes) es >= fiscal_period_start configurado.
     *   - Su inicio es < primer día del mes actual (el mes en curso NO cuenta:
     *     aún no se puede declarar porque no ha terminado).
     *   - Su estado es abierto (nunca declarado, o reabierto sin re-declarar).
     *
     * Si `fiscal_period_start` no está configurado retorna colección vacía en
     * vez de lanzar — consumidores (widget, job) degradan silenciosamente
     * cuando el módulo no está habilitado en la empresa.
     *
     * Trade-off de la separación command/query: si el scheduler aún no ha corrido
     * el `ensureOverduePeriodsExist()` del día (ej: primera vez tras configurar
     * fiscal_period_start), el resultado puede sub-reportar meses sin actividad.
     * El job diario garantiza convergencia en menos de 24h. Para entornos donde
     * eso no alcance se puede invocar el ensure desde un controller/page hook.
     *
     * @return Collection<int, FiscalPeriod> Ordenados por period_year, period_month ASC.
     */
    public function listOverdue(): Collection
    {
        $company = CompanySetting::current();

        if ($company->fiscal_period_start === null) {
            return collect();
        }

        $start = CarbonImmutable::instance($company->fiscal_period_start)->startOfMonth();
        $currentMonthStart = CarbonImmutable::now()->startOfMonth();

        if ($start->greaterThanOrEqualTo($currentMonthStart)) {
            return collect();
        }

        // Solo abiertos, dentro del rango [start, currentMonthStart),
        // ordenados cronológicamente (más antiguos primero = más urgentes).
        return FiscalPeriod::open()
            ->where(function ($query) use ($start, $currentMonthStart) {
                // Rango por (year, month) usando un OR comprimido por año.
                // Equivalente lógico a: (year > startY) OR (year == startY AND month >= startM)
                //                  AND  (year < endY)   OR (year == endY   AND month <  endM)
                $query
                    ->where(function ($q) use ($start) {
                        $q->where('period_year', '>', $start->year)
                            ->orWhere(function ($qq) use ($start) {
                                $qq->where('period_year', $start->year)
                                    ->where('period_month', '>=', $start->month);
                            });
                    })
                    ->where(function ($q) use ($currentMonthStart) {
                        $q->where('period_year', '<', $currentMonthStart->year)
                            ->orWhere(function ($qq) use ($currentMonthStart) {
                                $qq->where('period_year', $currentMonthStart->year)
                                    ->where('period_month', '<', $currentMonthStart->month);
                            });
                    });
            })
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->get();
    }

    /**
     * Cuenta períodos vencidos sin declarar — versión barata para badges.
     *
     * QUERY PURA. Memoizada por instancia del Service para que el navigation
     * badge (que llama esto 2 veces consecutivas: getNavigationBadge +
     * getNavigationBadgeColor) ejecute una sola COUNT por render.
     *
     * El service vive como singleton dentro del request (Laravel container),
     * así que la memo cubre todo el ciclo de vida del HTTP request actual.
     */
    public function countOverdue(): int
    {
        if ($this->overdueCountMemo !== null) {
            return $this->overdueCountMemo;
        }

        $company = CompanySetting::current();

        if ($company->fiscal_period_start === null) {
            return $this->overdueCountMemo = 0;
        }

        $start = CarbonImmutable::instance($company->fiscal_period_start)->startOfMonth();
        $currentMonthStart = CarbonImmutable::now()->startOfMonth();

        if ($start->greaterThanOrEqualTo($currentMonthStart)) {
            return $this->overdueCountMemo = 0;
        }

        return $this->overdueCountMemo = FiscalPeriod::open()
            ->where(function ($query) use ($start, $currentMonthStart) {
                $query
                    ->where(function ($q) use ($start) {
                        $q->where('period_year', '>', $start->year)
                            ->orWhere(function ($qq) use ($start) {
                                $qq->where('period_year', $start->year)
                                    ->where('period_month', '>=', $start->month);
                            });
                    })
                    ->where(function ($q) use ($currentMonthStart) {
                        $q->where('period_year', '<', $currentMonthStart->year)
                            ->orWhere(function ($qq) use ($currentMonthStart) {
                                $qq->where('period_year', $currentMonthStart->year)
                                    ->where('period_month', '<', $currentMonthStart->month);
                            });
                    });
            })
            ->count();
    }

    /** Memo de countOverdue() para evitar re-query dentro del mismo request. */
    private ?int $overdueCountMemo = null;

    // ─── Comandos (modifican estado) ─────────────────────

    /**
     * Marcar un período como declarado al SAR.
     *
     * Idempotente: llamarlo dos veces sobre el mismo período sin reapertura
     * lanza PeriodoFiscalYaDeclaradoException — esa segunda llamada indica
     * error operativo (doble presentación por accidente).
     *
     * @throws PeriodoFiscalYaDeclaradoException Si el período ya estaba cerrado.
     */
    public function declare(
        FiscalPeriod $period,
        User $declaredBy,
        ?string $notes = null,
    ): void {
        DB::transaction(function () use ($period, $declaredBy, $notes) {
            $fresh = FiscalPeriod::lockForUpdate()->findOrFail($period->id);

            // Bloqueo solo si está declarado Y no reabierto (estado "cerrado" real).
            if ($fresh->isClosed()) {
                throw new PeriodoFiscalYaDeclaradoException(
                    periodYear: $fresh->period_year,
                    periodMonth: $fresh->period_month,
                    declaredAt: $fresh->declared_at,
                );
            }

            $fresh->update([
                'declared_at' => now(),
                'declared_by' => $declaredBy->id,
                'declaration_notes' => $notes,
            ]);
        });
    }

    /**
     * Reabrir un período declarado para presentar declaración rectificativa.
     *
     * Solo aplica a períodos CERRADOS. Reabrir un período ya abierto no tiene
     * sentido operativo (ver PeriodoFiscalYaReabiertoException).
     *
     * El motivo es obligatorio (rastro de auditoría). El usuario que reabre
     * debe tener permiso admin — esa verificación vive en la Policy, no aquí.
     *
     * @throws PeriodoFiscalYaReabiertoException Si el período ya está abierto.
     */
    public function reopen(
        FiscalPeriod $period,
        User $reopenedBy,
        string $reason,
    ): void {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException(
                'El motivo de reapertura es obligatorio para el rastro de auditoría.'
            );
        }

        DB::transaction(function () use ($period, $reopenedBy, $reason) {
            $fresh = FiscalPeriod::lockForUpdate()->findOrFail($period->id);

            if ($fresh->isOpen()) {
                throw new PeriodoFiscalYaReabiertoException(
                    periodYear: $fresh->period_year,
                    periodMonth: $fresh->period_month,
                    reopenedAt: $fresh->reopened_at,
                );
            }

            $fresh->update([
                'reopened_at' => now(),
                'reopened_by' => $reopenedBy->id,
                'reopen_reason' => $reason,
            ]);
        });
    }

    // ─── Privados ────────────────────────────────────────

    /**
     * Verifica que la empresa haya configurado fiscal_period_start.
     *
     * @throws PeriodoFiscalNoConfiguradoException
     */
    private function assertConfigured(): void
    {
        $company = CompanySetting::current();

        if ($company->fiscal_period_start === null) {
            throw new PeriodoFiscalNoConfiguradoException();
        }
    }

    /**
     * Recuperar o crear el período para un año/mes.
     *
     * Usa firstOrCreate que es atómico a nivel SQL (UNIQUE constraint en
     * year+month previene carreras de creación duplicada).
     */
    private function findOrCreateForMonth(int $year, int $month): FiscalPeriod
    {
        return FiscalPeriod::firstOrCreate(
            ['period_year' => $year, 'period_month' => $month],
            []
        );
    }

    /**
     * Lookup memoizado de todos los períodos fiscales existentes, indexado
     * por "year-month". Usado por `canVoidInvoice` para evitar N+1 cuando
     * el método se invoca una vez por fila de un listado paginado.
     *
     * Escala: con UNIQUE(year, month), la tabla tiene como máximo 12 filas
     * por año de operación. Traer todo a memoria es trivial y amortiza una
     * sola query contra cualquier número de calls dentro del request.
     *
     * La memo se invalida al destruir el service (fin del request) — no hay
     * riesgo de leer estados viejos entre requests.
     *
     * Si en el futuro la tabla crece significativamente (ej: períodos diarios
     * en vez de mensuales) se puede restringir el scope por rango de años
     * relevantes pasando parámetros; hoy no se necesita.
     *
     * @return Collection<string, FiscalPeriod>
     */
    private function loadFiscalPeriodsMap(): Collection
    {
        if ($this->fiscalPeriodsMemo !== null) {
            return $this->fiscalPeriodsMemo;
        }

        return $this->fiscalPeriodsMemo = FiscalPeriod::query()
            ->get(['id', 'period_year', 'period_month', 'declared_at', 'reopened_at'])
            ->keyBy(fn (FiscalPeriod $p) => "{$p->period_year}-{$p->period_month}");
    }

    /** Memo del map de fiscal_periods para amortizar queries en listados. */
    private ?Collection $fiscalPeriodsMemo = null;
}
