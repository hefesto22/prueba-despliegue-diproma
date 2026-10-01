<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Authorization\CustomPermission;
use App\Models\Tithe;
use App\Services\Finance\Exceptions\DeduccionDiezmoInvalidaException;
use App\Services\Finance\Exceptions\MesDiezmoInvalidoException;
use App\Services\Finance\MonthlyProfit;
use App\Services\Finance\MonthlyProfitCalculator;
use App\Services\Finance\TitheBreakdown;
use App\Services\Finance\TitheService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Width;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Escritorio: diezmo del mes (10% de la utilidad).
 *
 * La tarjeta muestra el diezmo del mes en curso y los últimos guardados. El
 * botón "Sacar diezmo del mes" abre un modal con la utilidad del sistema para
 * el mes elegido, donde se agregan los pagos del negocio que el sistema no
 * conoce (pago de empleados, etc.) y se guarda el resultado (TitheService).
 *
 * Visible solo con el permiso Calculate:Tithe: son cifras del dueño.
 */
class TitheWidget extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    // Junto a la Utilidad Neta (NetProfitOverview, sort 3): se registra
    // después en el panel y el orden estable lo deja justo debajo.
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.tithe-widget';

    /**
     * Inyectados en boot(); protected para que Livewire no los serialice.
     */
    protected TitheService $tithes;

    protected MonthlyProfitCalculator $profits;

    /**
     * Utilidad por período, para no recalcularla en cada render del modal
     * dentro del mismo request.
     *
     * @var array<string, MonthlyProfit>
     */
    protected array $profitByPeriod = [];

    public function boot(TitheService $tithes, MonthlyProfitCalculator $profits): void
    {
        $this->tithes = $tithes;
        $this->profits = $profits;
    }

    public static function canView(): bool
    {
        return auth()->user()?->can(CustomPermission::CalculateTithe->value) ?? false;
    }

    public function calculateTitheAction(): Action
    {
        return Action::make('calculateTithe')
            ->label('Sacar diezmo del mes')
            ->icon('heroicon-o-calculator')
            ->modalHeading('Diezmo del mes')
            ->modalDescription('10% de la utilidad del mes, después de restar los pagos del negocio que no están en el sistema.')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Guardar diezmo')
            ->authorize(fn (): bool => static::canView())
            ->fillForm(fn (): array => $this->formStateFor(self::currentPeriod()))
            ->schema([
                Select::make('period')
                    ->label('Mes')
                    ->options(self::periodOptions())
                    ->in(array_keys(self::periodOptions()))
                    ->required()
                    ->native(false)
                    ->live()
                    // Al cambiar de mes se cargan los pagos que ya se habían
                    // guardado para ese mes (o ninguno).
                    ->afterStateUpdated(function (?string $state, callable $set): void {
                        if (blank($state)) {
                            return;
                        }

                        $fill = $this->formStateFor($state);
                        $set('deductions', $fill['deductions']);
                        $set('notes', $fill['notes']);
                    }),
                Placeholder::make('system_profit')
                    ->hiddenLabel()
                    ->content(fn (callable $get): HtmlString => $this->renderSummary($get, 'system')),
                Repeater::make('deductions')
                    ->label('Pagos del negocio que no están en el sistema')
                    ->helperText('Pago de empleados u otros. No repita gastos ya registrados en Compras: ya están restados arriba.')
                    ->schema([
                        TextInput::make('concept')
                            ->label('Concepto')
                            ->placeholder('Ej. Pago de empleados')
                            ->required()
                            ->maxLength(150)
                            ->columnSpan(2),
                        TextInput::make('amount')
                            ->label('Monto')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->prefix('L')
                            ->live(onBlur: true),
                    ])
                    ->columns(3)
                    ->defaultItems(0)
                    ->addActionLabel('Agregar pago')
                    ->reorderable(false)
                    ->live(),
                Placeholder::make('tithe_result')
                    ->hiddenLabel()
                    ->content(fn (callable $get): HtmlString => $this->renderSummary($get, 'result')),
                Textarea::make('notes')
                    ->label('Notas (opcional)')
                    ->rows(2)
                    ->maxLength(1000),
            ])
            ->action(function (array $data, Action $action): void {
                [$year, $month] = self::parsePeriod((string) $data['period']);

                try {
                    $this->tithes->save($year, $month, array_values($data['deductions'] ?? []), $data['notes'] ?? null);
                } catch (MesDiezmoInvalidoException|DeduccionDiezmoInvalidaException $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo guardar el diezmo')
                        ->body($e->getMessage())
                        ->send();

                    $action->halt();

                    return;
                }

                $saved = $this->tithes->forMonth($year, $month);

                Notification::make()
                    ->success()
                    ->title("Diezmo de {$saved?->periodLabel()}: L ".number_format((float) $saved?->amount, 2))
                    ->send();
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        [$year, $month] = self::parsePeriod(self::currentPeriod());

        return [
            'currentLabel' => self::periodLabel(self::currentPeriod()),
            'current' => $this->tithes->forMonth($year, $month),
            'recent' => Tithe::query()
                ->select(['id', 'year', 'month', 'amount', 'updated_at'])
                ->orderByDesc('year')
                ->orderByDesc('month')
                ->limit(6)
                ->get(),
        ];
    }

    /**
     * Estado inicial del modal para un mes: sus pagos y notas guardados, si
     * ya se había sacado el diezmo.
     *
     * @return array{period: string, deductions: array<string, array{concept: string, amount: string}>, notes: ?string}
     */
    private function formStateFor(string $period): array
    {
        [$year, $month] = self::parsePeriod($period);
        $saved = $this->tithes->forMonth($year, $month);

        // Llaves UUID: el Repeater identifica cada fila por su llave.
        $deductions = ($saved?->deductions ?? new Collection)
            ->mapWithKeys(fn ($deduction): array => [(string) Str::uuid() => [
                'concept' => $deduction->concept,
                'amount' => (string) $deduction->amount,
            ]])
            ->all();

        return [
            'period' => $period,
            'deductions' => $deductions,
            'notes' => $saved?->notes,
        ];
    }

    private function renderSummary(callable $get, string $part): HtmlString
    {
        $period = (string) $get('period');

        if (! array_key_exists($period, self::periodOptions())) {
            return new HtmlString('');
        }

        $profit = $this->profitFor($period);

        // Montos aún vacíos o a medio teclear cuentan como 0; uno negativo
        // lo rechaza la validación del campo, aquí no se suma.
        $amounts = collect($get('deductions') ?? [])
            ->pluck('amount')
            ->map(fn ($amount): float => is_numeric($amount) ? max(0.0, (float) $amount) : 0.0)
            ->all();

        return new HtmlString(view('filament.forms.tithe-summary', [
            'part' => $part,
            'periodLabel' => self::periodLabel($period),
            'profit' => $profit,
            'breakdown' => TitheBreakdown::from($profit, $amounts),
        ])->render());
    }

    private function profitFor(string $period): MonthlyProfit
    {
        if (! isset($this->profitByPeriod[$period])) {
            [$year, $month] = self::parsePeriod($period);
            $this->profitByPeriod[$period] = $this->profits->forMonth($year, $month);
        }

        return $this->profitByPeriod[$period];
    }

    /**
     * Mes en curso y los 11 anteriores, del más reciente al más antiguo.
     *
     * @return array<string, string> 'Y-m' => 'Septiembre 2026'
     */
    private static function periodOptions(): array
    {
        $options = [];
        $month = CarbonImmutable::now()->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $period = $month->subMonthsNoOverflow($i)->format('Y-m');
            $options[$period] = self::periodLabel($period);
        }

        return $options;
    }

    private static function currentPeriod(): string
    {
        return CarbonImmutable::now()->format('Y-m');
    }

    /**
     * @return array{0: int, 1: int} [año, mes]
     */
    private static function parsePeriod(string $period): array
    {
        $date = CarbonImmutable::createFromFormat('!Y-m', $period);

        return [(int) $date->year, (int) $date->month];
    }

    private static function periodLabel(string $period): string
    {
        return ucfirst(CarbonImmutable::createFromFormat('!Y-m', $period)->translatedFormat('F Y'));
    }
}
