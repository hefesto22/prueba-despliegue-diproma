<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Exports\PurchaseBook\PurchaseBookExport;
use App\Exports\SalesBook\SalesBookExport;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\FiscalPeriod;
use App\Services\FiscalBooks\PurchaseBookService;
use App\Services\FiscalBooks\SalesBookService;
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
use Maatwebsite\Excel\Facades\Excel;

/**
 * Generador on-demand de Libros Fiscales SAR (Ventas y Compras).
 *
 * A diferencia de los actions en FiscalPeriodsTable — que requieren que exista
 * el registro del período (solo se crea cuando el mes está vencido) — esta
 * página genera los libros para cualquier mes dentro del rango válido,
 * incluyendo el mes en curso.
 *
 * Caso de uso cubierto: el usuario quiere ver cómo va el libro del mes actual
 * antes de que termine, para conciliaciones internas o cierres anticipados
 * con el contador. También permite regenerar libros de meses pasados (aunque
 * ya estén declarados) sin depender del listado de FiscalPeriods.
 *
 * Reutiliza SalesBookService y PurchaseBookService sin modificarlos — esta
 * Page es solo orquestación UI: recoger selección, validar rango, delegar al
 * service, descargar el Excel.
 */
class FiscalBooks extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Libros Fiscales';

    protected static ?string $title = 'Libros Fiscales SAR';

    protected static ?string $slug = 'fiscal-books';

    // 98 — entre Declaraciones ISV (97) y Configuración Empresa (99).
    protected static ?int $navigationSort = 98;

    protected string $view = 'filament.pages.fiscal-books';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return 'Administración';
    }

    /**
     * Misma política que Declaraciones ISV: quien puede ver períodos puede
     * generar los libros. Coherente con que son vistas distintas de la misma
     * información fiscal.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', FiscalPeriod::class) === true;
    }

    public function mount(): void
    {
        $now = CarbonImmutable::now();

        $this->form->fill([
            'period_year'      => $now->year,
            'period_month'     => $now->month,
            'establishment_id' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Seleccionar período')
                    ->description('Elija el mes del libro a generar. Sucursal opcional — por defecto es company-wide (así se declara al SAR).')
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
                                ->helperText('Opcional. Déjelo vacío para company-wide.')
                                ->native(false),
                        ]),
                    ]),
            ]);
    }

    /**
     * Acciones de descarga en el header: una por cada libro.
     *
     * Se colocan como header actions (no como botones del form) para
     * mantenerlas visibles sin scroll y señalar que son la acción principal
     * de la página — el form solo configura parámetros.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('libro_ventas')
                ->label('Descargar Libro de Ventas')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->action(fn (SalesBookService $service) => $this->downloadSalesBook($service)),

            Action::make('libro_compras')
                ->label('Descargar Libro de Compras')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (PurchaseBookService $service) => $this->downloadPurchaseBook($service)),
        ];
    }

    private function downloadSalesBook(SalesBookService $service): mixed
    {
        $selection = $this->validatedSelection();
        if ($selection === null) {
            return null;
        }

        [$year, $month, $establishmentId] = $selection;

        try {
            $book   = $service->build($year, $month, $establishmentId);
            $export = new SalesBookExport($book);

            return Excel::download($export, $export->fileName());
        } catch (\InvalidArgumentException $e) {
            Notification::make()
                ->title('No se pudo generar el Libro de Ventas')
                ->body("Período inválido: {$e->getMessage()}")
                ->danger()
                ->persistent()
                ->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->title('Error generando el Libro de Ventas')
                ->body('Ocurrió un error inesperado. El equipo técnico fue notificado.')
                ->danger()
                ->persistent()
                ->send();
        }

        return null;
    }

    private function downloadPurchaseBook(PurchaseBookService $service): mixed
    {
        $selection = $this->validatedSelection();
        if ($selection === null) {
            return null;
        }

        [$year, $month, $establishmentId] = $selection;

        try {
            $book   = $service->build($year, $month, $establishmentId);
            $export = new PurchaseBookExport($book);

            return Excel::download($export, $export->fileName());
        } catch (\InvalidArgumentException $e) {
            Notification::make()
                ->title('No se pudo generar el Libro de Compras')
                ->body("Período inválido: {$e->getMessage()}")
                ->danger()
                ->persistent()
                ->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->title('Error generando el Libro de Compras')
                ->body('Ocurrió un error inesperado. El equipo técnico fue notificado.')
                ->danger()
                ->persistent()
                ->send();
        }

        return null;
    }

    /**
     * Valida que el período seleccionado esté dentro del rango permitido:
     * [fiscal_period_start, mes actual]. Retorna null si la validación falla
     * (con notificación al usuario ya enviada).
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
        $start    = CompanySetting::current()->fiscal_period_start;

        if ($start === null) {
            Notification::make()
                ->title('Configuración incompleta')
                ->body('Debe configurar "Inicio del Período Fiscal" en Configuración Empresa antes de generar libros.')
                ->danger()
                ->persistent()
                ->send();

            return null;
        }

        $startMonth = CarbonImmutable::instance($start)->startOfMonth();

        if ($selected->greaterThan($now)) {
            Notification::make()
                ->title('Período no válido')
                ->body('No se pueden generar libros de meses futuros.')
                ->warning()
                ->send();

            return null;
        }

        if ($selected->lessThan($startMonth)) {
            Notification::make()
                ->title('Período fuera de rango')
                ->body(
                    "El período seleccionado es anterior al inicio del tracking fiscal ({$startMonth->format('m/Y')}). "
                    . 'Las facturas previas a esa fecha no están en el libro.'
                )
                ->warning()
                ->send();

            return null;
        }

        return [$year, $month, $estId];
    }

    /**
     * Años disponibles: desde el año de fiscal_period_start hasta el año actual,
     * en orden descendente (año actual primero — caso más frecuente).
     *
     * @return array<int, string>
     */
    private function yearOptions(): array
    {
        $start = CompanySetting::current()->fiscal_period_start;

        if ($start === null) {
            // Fallback defensivo: solo año actual para no romper la UI si alguien
            // accede sin haber configurado fiscal_period_start. La validación
            // posterior rechazará la descarga con mensaje claro.
            $current = CarbonImmutable::now()->year;

            return [$current => (string) $current];
        }

        $startYear = CarbonImmutable::instance($start)->year;
        $endYear   = CarbonImmutable::now()->year;

        $years = [];
        for ($y = $endYear; $y >= $startYear; $y--) {
            $years[$y] = (string) $y;
        }

        return $years;
    }
}
