<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Exports\ExpensesMonthly\ExpensesMonthlyExport;
use App\Models\Establishment;
use App\Models\Expense;
use App\Services\Expenses\ExpensesMonthlyReport;
use App\Services\Expenses\ExpensesMonthlyReportService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Reporte Mensual de Gastos.
 *
 * Vista on-demand para que el contador (y el admin) revisen los gastos del
 * mes ANTES del 10 del mes siguiente — fecha en que se paga el ISV al SAR.
 *
 * Caso de uso real: el contador hace los pagos de ISV el día 10 de cada
 * mes. Antes de esa fecha necesita:
 *   1. Ver el `creditoFiscalDeducible` que va al Formulario 201 ("crédito
 *      fiscal por compras y gastos").
 *   2. Identificar `deduciblesIncompletos` (gastos marcados deducibles que
 *      no tienen RTN, # factura o CAI completos) — son los que SAR puede
 *      rechazar en una auditoría.
 *   3. Comparar gastos por categoría / método de pago / sucursal para
 *      cierre interno y conciliación.
 *
 * Diferencia con `DeclaracionIsvMensual`:
 *   - Aquella es el flujo de presentación al SAR (snapshots inmutables).
 *   - Esta es revisión y archivo: el contador valida calidad de datos antes
 *     de tomar el monto para la declaración. No persiste nada — solo
 *     calcula y exporta.
 *
 * POR QUÉ NO ES UN RESOURCE
 * ─────────────────────────
 * No hay CRUD aquí. El usuario ya gestiona gastos en `ExpenseResource`. Esta
 * Page es UN reporte (lectura+export), por lo que un Resource sería over-
 * engineering: heredaríamos botones de Edit/Delete que no aplican.
 *
 * AUTORIZACIÓN
 * ────────────
 * `canAccess`: cualquier usuario autenticado con permiso `viewAny` sobre
 * `Expense` puede ver este reporte (el rol "contador" tiene viewAny pero no
 * create/update — perfecto para esta página, que es read-only por diseño).
 *
 * El permiso de la Page (`Page:ReporteGastosMensual`) se genera vía Shield
 * y se asigna a admin + contador. La auth de la Page es defense-in-depth
 * sobre el viewAny del Expense.
 */
class ReporteGastosMensual extends Page implements HasForms
{
    use InteractsWithForms;

    // Icono distinto al de ExpenseResource (`OutlinedClipboardDocumentList`)
    // para que en el sidebar el reporte no se confunda con el CRUD de gastos.
    // `DocumentChartBar` = "documento con gráfico" → semántica de reporte.
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $navigationLabel = 'Reporte Mensual de Gastos';

    protected static ?string $title = 'Reporte Mensual de Gastos';

    protected static ?string $slug = 'reporte-gastos-mensual';

    // Después de "Gastos" (CRUD del módulo) — flujo natural: primero registrar,
    // luego revisar. El sort exacto no importa mientras quede en el mismo
    // grupo de Finanzas.
    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.pages.reporte-gastos-mensual';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    // ─── Estado del reporte cargado ──────────────────────────
    //
    // Persistimos los PARÁMETROS del reporte cargado (no el DTO entero).
    // Razón: Livewire 3 solo serializa primitivos / Eloquent / enums / Carbon.
    // El `ExpensesMonthlyReport` es un DTO custom con Collection<DTO> dentro
    // — Livewire no sabe cómo serializarlo y crashea con "Property type not
    // supported". Guardamos los parámetros (3 ints) y reconstruimos el DTO
    // al vuelo en el getter computed.
    //
    // Trade-off: cada render de la vista recalcula el reporte (queries SQL +
    // agregaciones). Aceptable: la operación es ~50ms y la página no se
    // refresca masivamente. La alternativa (implementar Wireable en los 3
    // DTOs) duplica código de serialización y aumenta superficie de bugs.

    public ?int $loadedYear = null;
    public ?int $loadedMonth = null;
    public ?int $loadedEstablishmentId = null;

    /**
     * DTO del reporte cargado. Computed: se reconstruye desde los parámetros
     * persistidos en cada acceso. Livewire cachea el resultado dentro de un
     * mismo request (#[Computed]) — render + visibility de export = 1 build.
     *
     * Retorna null mientras no se haya disparado "Cargar reporte".
     */
    #[Computed]
    public function report(): ?ExpensesMonthlyReport
    {
        if ($this->loadedYear === null || $this->loadedMonth === null) {
            return null;
        }

        return app(ExpensesMonthlyReportService::class)->build(
            $this->loadedYear,
            $this->loadedMonth,
            $this->loadedEstablishmentId,
        );
    }

    // ─── Configuración Filament ──────────────────────────────

    public static function getNavigationGroup(): ?string
    {
        return 'Documentos';
    }

    /**
     * Quien puede ver gastos accede al reporte. El rol "contador" cumple
     * ambos: ve gastos y debe ver este reporte para sus pagos de ISV.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', Expense::class) === true;
    }

    public function mount(): void
    {
        // Default inteligente:
        //   - Si estamos del 1 al 10 del mes (período de declaración) → mes anterior
        //     (es lo que el contador está revisando para declarar).
        //   - Si estamos del 11 en adelante → mes en curso (revisión preventiva
        //     del próximo cierre).
        $now = CarbonImmutable::now();
        $defaultPeriod = $now->day <= 10 ? $now->subMonthNoOverflow() : $now;

        $this->form->fill([
            'period_year'      => $defaultPeriod->year,
            'period_month'     => $defaultPeriod->month,
            'establishment_id' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Período del reporte')
                    ->description('Por defecto: si hoy es el día 1-10 del mes, carga el mes anterior (lo que el contador revisa antes del pago de ISV). Después del día 10, carga el mes actual.')
                    ->icon('heroicon-o-calendar-days')
                    ->schema([
                        Grid::make(3)->schema([
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

                            Select::make('establishment_id')
                                ->label('Sucursal')
                                ->options(fn () => Establishment::query()
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->placeholder('Todas las sucursales')
                                ->helperText('Opcional. Vacío = company-wide.')
                                ->native(false),
                        ]),
                    ]),
            ]);
    }

    // ─── Header actions ──────────────────────────────────────

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cargar_reporte')
                ->label('Cargar reporte')
                ->icon('heroicon-o-magnifying-glass')
                ->color('primary')
                ->action(function (ExpensesMonthlyReportService $service) {
                    $this->loadReport($service);
                }),

            Action::make('exportar_excel')
                ->label('Exportar a Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->report !== null)
                ->action(function () {
                    if ($this->report === null) {
                        return null;
                    }

                    $export = new ExpensesMonthlyExport($this->report);

                    return Excel::download($export, $export->fileName());
                }),
        ];
    }

    // ─── Handlers ────────────────────────────────────────────

    private function loadReport(ExpensesMonthlyReportService $service): void
    {
        $selection = $this->validatedSelection();
        if ($selection === null) {
            return;
        }

        [$year, $month, $establishmentId] = $selection;

        // Probamos el build aquí (sincrónico) para detectar errores ANTES de
        // persistir los parámetros. Si truena, no marcamos el reporte como
        // cargado y damos feedback al usuario. El reporte real se reconstruye
        // en cada render via la computed property.
        try {
            $report = $service->build($year, $month, $establishmentId);
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->title('Error generando el reporte')
                ->body('Ocurrió un error inesperado. El equipo técnico fue notificado.')
                ->danger()
                ->persistent()
                ->send();

            $this->loadedYear = null;
            $this->loadedMonth = null;
            $this->loadedEstablishmentId = null;

            return;
        }

        // Persistimos solo los parámetros — la computed property reconstruye
        // el DTO al renderizar. Esto evita el bug "Property type not supported"
        // de Livewire 3 con DTOs custom.
        $this->loadedYear = $year;
        $this->loadedMonth = $month;
        $this->loadedEstablishmentId = $establishmentId;

        $s = $report->summary;

        $body = "Gastos: {$s->gastosCount}  ·  Total: " . number_format($s->gastosTotal, 2) . ' L';
        if ($s->hasIncompleteWarnings()) {
            $body .= "  ·  ⚠ {$s->deduciblesIncompletosCount} deducibles incompletos";
        }

        Notification::make()
            ->title("Reporte cargado: {$s->periodLabel()}")
            ->body($body)
            ->success()
            ->send();
    }

    // ─── Utilities ───────────────────────────────────────────

    /**
     * Valida la selección del form.
     *
     * @return array{0: int, 1: int, 2: int|null}|null
     */
    private function validatedSelection(): ?array
    {
        $data = $this->form->getState();

        $year  = (int) ($data['period_year'] ?? 0);
        $month = (int) ($data['period_month'] ?? 0);
        $estId = isset($data['establishment_id']) && $data['establishment_id'] !== '' && $data['establishment_id'] !== null
            ? (int) $data['establishment_id']
            : null;

        if ($year < 2000 || $month < 1 || $month > 12) {
            Notification::make()
                ->title('Período inválido')
                ->body('Seleccione un año y mes válidos.')
                ->warning()
                ->send();

            return null;
        }

        $selected = CarbonImmutable::create($year, $month, 1);
        $now      = CarbonImmutable::now()->startOfMonth();

        if ($selected->greaterThan($now)) {
            Notification::make()
                ->title('Período no válido')
                ->body('No se pueden generar reportes de meses futuros.')
                ->warning()
                ->send();

            return null;
        }

        return [$year, $month, $estId];
    }

    /**
     * Años disponibles: del año actual al 2024 (cuando arrancó el sistema).
     * En single-tenant, el rango es estable y conocido — no leemos
     * `fiscal_period_start` porque este reporte NO es fiscal SAR (es de
     * gestión interna, debe poder generarse aunque no esté configurado el
     * inicio del período fiscal).
     *
     * @return array<int, string>
     */
    private function yearOptions(): array
    {
        $currentYear = CarbonImmutable::now()->year;

        $years = [];
        for ($y = $currentYear; $y >= 2024; $y--) {
            $years[$y] = (string) $y;
        }

        return $years;
    }
}
