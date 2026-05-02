<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\CompanySetting;
use App\Models\FiscalPeriod;
use App\Models\IsvMonthlyDeclaration;
use App\Services\FiscalPeriods\Exceptions\DeclaracionIsvYaExisteException;
use App\Services\FiscalPeriods\Exceptions\FiscalPeriodException;
use App\Services\FiscalPeriods\Exceptions\PeriodoFiscalNoConfiguradoException;
use App\Services\FiscalPeriods\FiscalPeriodService;
use App\Services\FiscalPeriods\IsvMonthlyDeclarationService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Flujo de Declaración ISV Mensual (Formulario 201 SAR).
 *
 * UI guiada end-to-end para el ciclo de cierre fiscal mensual:
 *   1. Seleccionar año/mes del período.
 *   2. Cargar totales calculados (computeTotalsFor) — preview sin efectos
 *      secundarios de escritura sobre snapshots.
 *   3. Ejecutar la acción correspondiente según el estado del período:
 *        - open    + sin snapshot activo    → Declarar al SAR
 *        - closed  (declared)               → Reabrir (rectificativa)
 *        - open    + reopened + snapshot activo → Presentar rectificativa
 *
 * POR QUÉ UNA PAGE CUSTOM Y NO UN RESOURCE CRUD
 * ─────────────────────────────────────────────
 * El modelo `IsvMonthlyDeclaration` es INMUTABLE por diseño fiscal (un snapshot
 * nunca se edita ni se borra — las rectificativas crean un snapshot nuevo y
 * marcan el anterior como superseded). Un Filament Resource con `EditAction` y
 * `DeleteAction` ofrecería botones que el Observer bloquea — mala UX.
 *
 * Esta Page NO es CRUD. Es un flujo de COMANDOS que orquesta tres servicios:
 *   - `IsvMonthlyDeclarationService::computeTotalsFor` (query pura, preview)
 *   - `IsvMonthlyDeclarationService::declare` / `redeclare` (commands atómicos)
 *   - `FiscalPeriodService::reopen` (command para el estado intermedio)
 *
 * AUTORIZACIÓN
 * ─────────────────────────────────────────────
 * Acceso a la página: `viewAny` sobre FiscalPeriod (quien puede ver períodos
 * puede ver esta página — mismo grupo "Administración" que Libros Fiscales).
 *
 * Cada acción se restringe por permiso Spatie específico del enum
 * `CustomPermission` (single source of truth):
 *   - Declarar / Rectificar → `Declare:FiscalPeriod`
 *   - Reabrir               → `Reopen:FiscalPeriod`
 *
 * La visibilidad de los botones depende además del estado del período cargado
 * — nunca mostramos un botón que el service rechazará con excepción.
 *
 * CONCURRENCIA
 * ─────────────────────────────────────────────
 * Los services (declare/redeclare/reopen) ya usan DB::transaction +
 * lockForUpdate. Esta Page se limita a refrescar el estado local tras cada
 * acción para que un segundo contador que tenga la misma página abierta vea el
 * estado correcto después de F5. No implementamos polling reactivo — sería
 * over-engineering para un flujo que típicamente ejecuta una persona por mes.
 */
class DeclaracionIsvMensual extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $navigationLabel = 'Declaración ISV Mensual';

    protected static ?string $title = 'Declaración ISV Mensual — Formulario 201';

    protected static ?string $slug = 'declaracion-isv-mensual';

    // 96 — antes de "Declaraciones ISV" (listado histórico, 97) y de
    // Libros Fiscales (98) para reflejar el orden operativo natural:
    // primero declarar, luego consultar el histórico.
    protected static ?int $navigationSort = 96;

    protected string $view = 'filament.pages.declaracion-isv-mensual';

    // ─── Estado del form ─────────────────────────────────────

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    // ─── Estado cargado del período ──────────────────────────

    /**
     * ID del FiscalPeriod cargado. Null hasta que el usuario presiona
     * "Cargar período" con una selección válida.
     */
    public ?int $loadedFiscalPeriodId = null;

    /** Año del período cargado — copiado del form al momento de cargar. */
    public ?int $loadedYear = null;

    /** Mes del período cargado — copiado del form al momento de cargar. */
    public ?int $loadedMonth = null;

    /**
     * Estado del período cargado: 'open' | 'declared' | 'reopened' | null.
     *   - open     → nunca declarado
     *   - declared → cerrado al SAR (candidato a reabrir)
     *   - reopened → reabierto, pendiente de rectificativa
     */
    public ?string $periodStatus = null;

    /**
     * Totales calculados (computeTotalsFor → toArray). Las claves coinciden
     * con los fillable del modelo IsvMonthlyDeclaration para que la view las
     * consuma simétricamente en ambos flujos (preview vs. snapshot activo).
     *
     * @var array<string, float>|null
     */
    public ?array $computedTotals = null;

    /**
     * Snapshot activo del período si existe. Null si `periodStatus === 'open'`
     * (nunca declarado) o si el contador está en el mid-step de una
     * rectificativa.
     *
     * @var array<string, mixed>|null
     */
    public ?array $activeSnapshot = null;

    /**
     * Historial de snapshots supersedidos (rectificativas anteriores),
     * ordenados desde el más reciente al más antiguo.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $rectificativasHistory = [];

    // ─── Configuración Filament ──────────────────────────────

    public static function getNavigationGroup(): ?string
    {
        return 'Fiscal';
    }

    /**
     * Misma política que FiscalBooks: quien puede ver períodos accede al
     * flujo. Las acciones de escritura (declarar / reabrir / rectificar)
     * tienen su propia guard vía `authorize(...)` sobre permisos Spatie
     * específicos — defense in depth.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', FiscalPeriod::class) === true;
    }

    public function mount(): void
    {
        $now = CarbonImmutable::now();

        $this->form->fill([
            'period_year'  => $now->year,
            'period_month' => $now->month,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Período fiscal a declarar')
                    ->description('Seleccione el mes del Formulario 201. El mes en curso se puede cargar para preview pero no se puede declarar hasta que termine.')
                    ->icon('heroicon-o-calendar-days')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('period_year')
                                ->label('Año')
                                ->options(fn () => $this->yearOptions())
                                ->required()
                                ->native(false),

                            Select::make('period_month')
                                ->label('Mes')
                                ->options([
                                    1  => 'Enero',
                                    2  => 'Febrero',
                                    3  => 'Marzo',
                                    4  => 'Abril',
                                    5  => 'Mayo',
                                    6  => 'Junio',
                                    7  => 'Julio',
                                    8  => 'Agosto',
                                    9  => 'Septiembre',
                                    10 => 'Octubre',
                                    11 => 'Noviembre',
                                    12 => 'Diciembre',
                                ])
                                ->required()
                                ->native(false),
                        ]),
                    ]),
            ]);
    }

    // ─── Header actions ──────────────────────────────────────

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cargar_periodo')
                ->label('Cargar período')
                ->icon('heroicon-o-calculator')
                ->color('primary')
                ->action(function (FiscalPeriodService $periods, IsvMonthlyDeclarationService $isv) {
                    $this->loadPeriodData($periods, $isv);
                }),

            // ── Declarar: primera declaración del período ──
            Action::make('declarar')
                ->label('Declarar al SAR')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn (): bool => $this->canShowDeclareAction())
                ->authorize(fn (): bool => auth()->user()?->can('Declare:FiscalPeriod') === true)
                ->schema([
                    TextInput::make('siisar_acuse')
                        ->label('Número de acuse SIISAR')
                        ->maxLength(50)
                        ->helperText('Opcional. Puede agregarlo después si aún no tiene el comprobante del portal.'),
                    Textarea::make('notes')
                        ->label('Notas de la declaración')
                        ->rows(3)
                        ->maxLength(1000),
                ])
                ->requiresConfirmation()
                ->modalHeading('Confirmar declaración ISV al SAR')
                ->modalDescription('Esta operación cerrará el período fiscal. Verifique los totales del Formulario 201 antes de continuar.')
                ->modalSubmitActionLabel('Sí, declarar')
                ->action(function (array $data, IsvMonthlyDeclarationService $isv) {
                    $this->executeDeclare($data, $isv);
                }),

            // ── Reabrir: habilitar rectificativa sobre período cerrado ──
            Action::make('reabrir')
                ->label('Reabrir período')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (): bool => $this->canShowReopenAction())
                ->authorize(fn (): bool => auth()->user()?->can('Reopen:FiscalPeriod') === true)
                ->schema([
                    Textarea::make('reason')
                        ->label('Motivo de la reapertura')
                        ->required()
                        ->rows(3)
                        ->maxLength(500)
                        ->helperText('Obligatorio. Queda en el rastro de auditoría del período.'),
                ])
                ->requiresConfirmation()
                ->modalHeading('Reabrir período para rectificativa')
                ->modalDescription('La declaración actual queda marcada como vigente hasta que presente la rectificativa. Presentar rectificativa creará un nuevo snapshot con los totales recalculados.')
                ->modalSubmitActionLabel('Sí, reabrir')
                ->action(function (array $data, FiscalPeriodService $periods) {
                    $this->executeReopen($data, $periods);
                }),

            // ── Rectificar: segunda+ declaración sobre período reabierto ──
            Action::make('rectificar')
                ->label('Presentar rectificativa')
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->visible(fn (): bool => $this->canShowRedeclareAction())
                ->authorize(fn (): bool => auth()->user()?->can('Declare:FiscalPeriod') === true)
                ->schema([
                    TextInput::make('siisar_acuse')
                        ->label('Número de acuse SIISAR (rectificativa)')
                        ->maxLength(50),
                    Textarea::make('notes')
                        ->label('Notas de la rectificativa')
                        ->rows(3)
                        ->maxLength(1000)
                        ->helperText('Describa el motivo del ajuste (ej: retenciones omitidas, NC no contabilizada, etc.).'),
                ])
                ->requiresConfirmation()
                ->modalHeading('Confirmar declaración rectificativa')
                ->modalDescription('La declaración anterior se marcará como reemplazada. Se creará un nuevo snapshot activo con los totales actualizados al momento de esta rectificativa.')
                ->modalSubmitActionLabel('Sí, rectificar')
                ->action(function (array $data, IsvMonthlyDeclarationService $isv) {
                    $this->executeRedeclare($data, $isv);
                }),

            // ── Imprimir hoja de trabajo del snapshot vigente ──
            // Link directo (sin modal, sin confirmación) — abre la ruta
            // `isv-declarations.print` en nueva pestaña para no perder el
            // estado del form. Solo visible cuando hay un snapshot activo
            // cargado; los supersedidos se reimprimen desde el historial
            // (fuera de esta Page, en el Resource DeclaracionesISV).
            Action::make('imprimir_hoja')
                ->label('Imprimir hoja')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->visible(fn (): bool => $this->activeSnapshot !== null)
                ->url(fn (): ?string => $this->activeSnapshot !== null
                    ? route('isv-declarations.print', ['isvMonthlyDeclaration' => $this->activeSnapshot['id']])
                    : null)
                ->openUrlInNewTab(),
        ];
    }

    // ─── Handlers de acciones ────────────────────────────────

    /**
     * Carga el período indicado en el form: resuelve/crea el FiscalPeriod,
     * calcula totales preview, lee snapshot activo + historial.
     *
     * Esta es la ÚNICA query a DB costosa de la Page — se dispara solo cuando
     * el usuario presiona el botón explícito, no reactivamente al cambiar los
     * selects. Eso evita que cambios rápidos de mes ejecuten 3 queries
     * (ventas + compras + retenciones) cada vez.
     */
    private function loadPeriodData(FiscalPeriodService $periods, IsvMonthlyDeclarationService $isv): void
    {
        $selection = $this->validatedSelection();
        if ($selection === null) {
            return;
        }

        [$year, $month] = $selection;

        try {
            // Lazy-create del FiscalPeriod si no existe. Es el mismo comportamiento
            // que tiene el scheduler diario (ensureOverduePeriodsExist) — aquí
            // lo adelantamos a petición del usuario.
            $period = $periods->forDate(CarbonImmutable::create($year, $month, 1));
        } catch (PeriodoFiscalNoConfiguradoException $e) {
            Notification::make()
                ->title('Configuración incompleta')
                ->body('Debe configurar "Inicio del Período Fiscal" en Configuración Empresa antes de cargar períodos.')
                ->danger()
                ->persistent()
                ->send();

            $this->resetLoadedState();

            return;
        }

        // Computar totales del estado operativo REAL del sistema ahora mismo.
        // Coincide con los totales que se persistirán si el usuario dispara
        // "Declarar" / "Rectificar" a continuación (garantía del Service).
        $totals = $isv->computeTotalsFor($period)->toArray();

        $active = IsvMonthlyDeclaration::query()
            ->forFiscalPeriod($period->id)
            ->active()
            ->with(['declaredByUser:id,name'])
            ->first();

        $history = IsvMonthlyDeclaration::query()
            ->forFiscalPeriod($period->id)
            ->superseded()
            ->with(['declaredByUser:id,name', 'supersededByUser:id,name'])
            ->orderByDesc('declared_at')
            ->get();

        $this->loadedFiscalPeriodId = $period->id;
        $this->loadedYear           = $period->period_year;
        $this->loadedMonth          = $period->period_month;
        $this->periodStatus         = $this->resolvePeriodStatus($period);
        $this->computedTotals       = $totals;
        $this->activeSnapshot       = $active !== null ? $this->snapshotToArray($active) : null;
        $this->rectificativasHistory = $history
            ->map(fn (IsvMonthlyDeclaration $s) => $this->snapshotToArray($s))
            ->all();

        Notification::make()
            ->title("Período cargado: {$period->period_label}")
            ->body(match ($this->periodStatus) {
                'open'     => 'Abierto — puede declarar cuando el mes haya vencido.',
                'declared' => 'Declarado al SAR — puede reabrir para rectificativa.',
                'reopened' => 'Reabierto — pendiente de presentar rectificativa.',
                default    => '',
            })
            ->success()
            ->send();
    }

    /**
     * Ejecuta la primera declaración del período.
     *
     * El Service lanza excepciones tipadas ante violaciones de dominio;
     * las traducimos a notificaciones user-friendly sin exponer stacktraces.
     */
    private function executeDeclare(array $data, IsvMonthlyDeclarationService $isv): void
    {
        $period = $this->reloadPeriodOrFail();
        if ($period === null) {
            return;
        }

        if (! $this->assertPeriodIsDeclarable($period)) {
            return;
        }

        try {
            $snapshot = $isv->declare(
                period: $period,
                declaredBy: auth()->user(),
                siisarAcuse: $this->cleanNullable($data['siisar_acuse'] ?? null),
                notes: $this->cleanNullable($data['notes'] ?? null),
            );

            Notification::make()
                ->title("Declaración ISV presentada — {$period->period_label}")
                ->body("Snapshot #{$snapshot->id} creado. Período cerrado al SAR.")
                ->success()
                ->send();

            $this->refreshLoadedState($period->id);
        } catch (DeclaracionIsvYaExisteException $e) {
            Notification::make()
                ->title('Ya existe una declaración activa')
                ->body($e->getMessage())
                ->warning()
                ->persistent()
                ->send();
        } catch (FiscalPeriodException $e) {
            // PeriodoFiscalYaDeclaradoException u otras del dominio — mensaje ya
            // diseñado para el usuario final.
            Notification::make()
                ->title('No se pudo declarar el período')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->title('Error presentando la declaración')
                ->body('Ocurrió un error inesperado. El equipo técnico fue notificado.')
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /**
     * Reabre el período para habilitar la rectificativa.
     */
    private function executeReopen(array $data, FiscalPeriodService $periods): void
    {
        $period = $this->reloadPeriodOrFail();
        if ($period === null) {
            return;
        }

        $reason = trim((string) ($data['reason'] ?? ''));

        try {
            $periods->reopen(
                period: $period,
                reopenedBy: auth()->user(),
                reason: $reason,
            );

            Notification::make()
                ->title("Período {$period->period_label} reabierto")
                ->body('Ahora puede presentar la declaración rectificativa.')
                ->success()
                ->send();

            $this->refreshLoadedState($period->id);
        } catch (FiscalPeriodException $e) {
            Notification::make()
                ->title('No se pudo reabrir el período')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        } catch (\InvalidArgumentException $e) {
            // El Service rechaza motivos vacíos con InvalidArgumentException.
            // La validación del form ya evita esto en UI, pero defense in depth.
            Notification::make()
                ->title('Motivo inválido')
                ->body($e->getMessage())
                ->warning()
                ->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->title('Error reabriendo el período')
                ->body('Ocurrió un error inesperado. El equipo técnico fue notificado.')
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /**
     * Presenta la declaración rectificativa.
     */
    private function executeRedeclare(array $data, IsvMonthlyDeclarationService $isv): void
    {
        $period = $this->reloadPeriodOrFail();
        if ($period === null) {
            return;
        }

        try {
            $snapshot = $isv->redeclare(
                period: $period,
                declaredBy: auth()->user(),
                siisarAcuse: $this->cleanNullable($data['siisar_acuse'] ?? null),
                notes: $this->cleanNullable($data['notes'] ?? null),
            );

            Notification::make()
                ->title("Rectificativa presentada — {$period->period_label}")
                ->body("Snapshot #{$snapshot->id} creado. Período cerrado al SAR.")
                ->success()
                ->send();

            $this->refreshLoadedState($period->id);
        } catch (FiscalPeriodException $e) {
            Notification::make()
                ->title('No se pudo presentar la rectificativa')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->title('Error presentando la rectificativa')
                ->body('Ocurrió un error inesperado. El equipo técnico fue notificado.')
                ->danger()
                ->persistent()
                ->send();
        }
    }

    // ─── Visibilidad de actions ──────────────────────────────

    public function canShowDeclareAction(): bool
    {
        return $this->loadedFiscalPeriodId !== null
            && $this->periodStatus === 'open'
            && $this->activeSnapshot === null;
    }

    public function canShowReopenAction(): bool
    {
        return $this->loadedFiscalPeriodId !== null
            && $this->periodStatus === 'declared';
    }

    public function canShowRedeclareAction(): bool
    {
        return $this->loadedFiscalPeriodId !== null
            && $this->periodStatus === 'reopened'
            && $this->activeSnapshot !== null;
    }

    // ─── Utilities ───────────────────────────────────────────

    /**
     * Valida la selección del form: año/mes dentro del rango válido y
     * company-setting configurado. Notifica al usuario y retorna null si
     * algún check falla.
     *
     * @return array{0: int, 1: int}|null
     */
    private function validatedSelection(): ?array
    {
        $data = $this->form->getState();

        $year  = (int) ($data['period_year'] ?? 0);
        $month = (int) ($data['period_month'] ?? 0);

        if ($year < 2000 || $month < 1 || $month > 12) {
            Notification::make()
                ->title('Período inválido')
                ->body('Seleccione un año y mes válidos.')
                ->warning()
                ->send();

            return null;
        }

        $start = CompanySetting::current()->fiscal_period_start;

        if ($start === null) {
            Notification::make()
                ->title('Configuración incompleta')
                ->body('Debe configurar "Inicio del Período Fiscal" en Configuración Empresa antes de cargar períodos.')
                ->danger()
                ->persistent()
                ->send();

            return null;
        }

        $selected   = CarbonImmutable::create($year, $month, 1);
        $now        = CarbonImmutable::now()->startOfMonth();
        $startMonth = CarbonImmutable::instance($start)->startOfMonth();

        if ($selected->greaterThan($now)) {
            Notification::make()
                ->title('Período no válido')
                ->body('No se pueden cargar períodos de meses futuros.')
                ->warning()
                ->send();

            return null;
        }

        if ($selected->lessThan($startMonth)) {
            Notification::make()
                ->title('Período fuera de rango')
                ->body(
                    "El período seleccionado es anterior al inicio del tracking fiscal ({$startMonth->format('m/Y')}). "
                    . 'Las declaraciones previas no viven en este sistema.'
                )
                ->warning()
                ->send();

            return null;
        }

        return [$year, $month];
    }

    /**
     * Valida que el período cargado sea declarable (no sea el mes en curso).
     *
     * El mes en curso se puede CARGAR (para ver totales parciales) pero no
     * DECLARAR — SAR solo acepta declaraciones de meses vencidos. Esta regla
     * vive en la UI porque los Services son motor-agnóstico: podrían aceptar
     * un snapshot del mes actual si alguien lo llama desde un Job de prueba.
     */
    private function assertPeriodIsDeclarable(FiscalPeriod $period): bool
    {
        $selected = CarbonImmutable::create($period->period_year, $period->period_month, 1);
        $now      = CarbonImmutable::now()->startOfMonth();

        if ($selected->greaterThanOrEqualTo($now)) {
            Notification::make()
                ->title('Período aún no vencido')
                ->body("El período {$period->period_label} es el mes en curso y aún no puede declararse. Espere a que termine el mes.")
                ->warning()
                ->send();

            return false;
        }

        return true;
    }

    /**
     * Recarga el período cargado desde DB. Útil antes de ejecutar un command
     * para asegurar que vemos el estado más reciente (evita race conditions
     * en UI con dos contadores abiertos).
     */
    private function reloadPeriodOrFail(): ?FiscalPeriod
    {
        if ($this->loadedFiscalPeriodId === null) {
            Notification::make()
                ->title('No hay período cargado')
                ->body('Cargue un período antes de ejecutar esta acción.')
                ->warning()
                ->send();

            return null;
        }

        return FiscalPeriod::find($this->loadedFiscalPeriodId);
    }

    /**
     * Refresca el estado local de la Page sin redisparar computeTotalsFor
     * completo. Se usa después de declarar/reabrir/rectificar para que la UI
     * refleje el nuevo estado sin una recarga del usuario.
     */
    private function refreshLoadedState(int $periodId): void
    {
        $period = FiscalPeriod::find($periodId);
        if ($period === null) {
            $this->resetLoadedState();

            return;
        }

        $active = IsvMonthlyDeclaration::query()
            ->forFiscalPeriod($period->id)
            ->active()
            ->with(['declaredByUser:id,name'])
            ->first();

        $history = IsvMonthlyDeclaration::query()
            ->forFiscalPeriod($period->id)
            ->superseded()
            ->with(['declaredByUser:id,name', 'supersededByUser:id,name'])
            ->orderByDesc('declared_at')
            ->get();

        $this->periodStatus         = $this->resolvePeriodStatus($period);
        $this->activeSnapshot       = $active !== null ? $this->snapshotToArray($active) : null;
        $this->rectificativasHistory = $history
            ->map(fn (IsvMonthlyDeclaration $s) => $this->snapshotToArray($s))
            ->all();
    }

    private function resetLoadedState(): void
    {
        $this->loadedFiscalPeriodId  = null;
        $this->loadedYear            = null;
        $this->loadedMonth           = null;
        $this->periodStatus          = null;
        $this->computedTotals        = null;
        $this->activeSnapshot        = null;
        $this->rectificativasHistory = [];
    }

    private function resolvePeriodStatus(FiscalPeriod $period): string
    {
        if ($period->declared_at === null) {
            return 'open';
        }

        return $period->wasReopened() && $period->reopened_at->greaterThan($period->declared_at)
            ? 'reopened'
            : 'declared';
    }

    /**
     * Serializa un snapshot para el estado Livewire de la Page.
     *
     * Solo se exponen los campos que la view consume — evita acarrear la
     * instancia completa del modelo a cada render (es pesada por los 12 casts
     * decimal:2).
     *
     * @return array<string, mixed>
     */
    private function snapshotToArray(IsvMonthlyDeclaration $s): array
    {
        return [
            'id'                        => $s->id,
            'declared_at'               => $s->declared_at?->format('d/m/Y H:i'),
            'declared_by_name'          => $s->declaredByUser?->name,
            'siisar_acuse_number'       => $s->siisar_acuse_number,
            'superseded_at'             => $s->superseded_at?->format('d/m/Y H:i'),
            'superseded_by_name'        => $s->supersededByUser?->name,
            'notes'                     => $s->notes,

            // Totales SAR
            'ventas_gravadas'           => (float) $s->ventas_gravadas,
            'ventas_exentas'            => (float) $s->ventas_exentas,
            'ventas_totales'            => (float) $s->ventas_totales,
            'compras_gravadas'          => (float) $s->compras_gravadas,
            'compras_exentas'           => (float) $s->compras_exentas,
            'compras_totales'           => (float) $s->compras_totales,
            'isv_debito_fiscal'         => (float) $s->isv_debito_fiscal,
            'isv_credito_fiscal'        => (float) $s->isv_credito_fiscal,
            'isv_retenciones_recibidas' => (float) $s->isv_retenciones_recibidas,
            'saldo_a_favor_anterior'    => (float) $s->saldo_a_favor_anterior,
            'isv_a_pagar'               => (float) $s->isv_a_pagar,
            'saldo_a_favor_siguiente'   => (float) $s->saldo_a_favor_siguiente,
        ];
    }

    /**
     * Trim + null si queda vacío. Evita persistir strings de espacios.
     */
    private function cleanNullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Años disponibles: desde el año de fiscal_period_start hasta el año
     * actual en orden descendente. Fallback si no está configurado: año
     * actual — la validación del action atrapa el caso real.
     *
     * @return array<int, string>
     */
    private function yearOptions(): array
    {
        $start = CompanySetting::current()->fiscal_period_start;

        $currentYear = CarbonImmutable::now()->year;

        if ($start === null) {
            return [$currentYear => (string) $currentYear];
        }

        $startYear = CarbonImmutable::instance($start)->year;

        $years = [];
        for ($y = $currentYear; $y >= $startYear; $y--) {
            $years[$y] = (string) $y;
        }

        return $years;
    }

    /**
     * Etiqueta humana del período cargado — para el view sin duplicar lógica.
     */
    public function getLoadedPeriodLabel(): ?string
    {
        if ($this->loadedYear === null || $this->loadedMonth === null) {
            return null;
        }

        return CarbonImmutable::create($this->loadedYear, $this->loadedMonth, 1)
            ->locale('es')
            ->translatedFormat('F Y');
    }
}
