<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cash\Actions;

use App\Exceptions\Cash\DescuadreExcedeTolerancianException;
use App\Exceptions\Cash\MovimientoEnSesionCerradaException;
use App\Models\CashSession;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\Cash\CashBalanceCalculator;
use App\Services\Cash\CashSessionService;
use BezhanSalleh\FilamentShield\Support\Utils;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;

/**
 * Action reutilizable de "Cerrar mi caja".
 *
 * Se factoriza para usar tanto en ListCashSessions (sin record, resuelve la
 * sesión abierta de la sucursal del user) como en ViewCashSession (con el
 * record de la sesión que se está viendo). Ambos casos delegan la resolución
 * de la sesión en un `$sessionResolver` inyectado — la action no sabe ni
 * le importa de dónde viene.
 *
 * Reglas de diseño aplicadas:
 *   - D3b: si |descuadre| > tolerancia, se exige Select de autorizador con
 *     rol `admin` o `super_admin` (excluye al propio user — quien cuenta no
 *     puede firmar su propio descuadre).
 *   - Preview en vivo del descuadre usando `live(onBlur: true)` + Placeholder
 *     reactivo. La fuente de verdad sigue siendo CashSessionService::close().
 *
 * NO se ocupa de:
 *   - Validar permisos (responsabilidad de la page/resource vía Policy Shield).
 *   - Cálculos (delegados a CashBalanceCalculator y CashSessionService).
 */
final class CloseCashSessionAction
{
    /**
     * @param  Closure(): ?CashSession  $sessionResolver  Retorna la sesión a cerrar o null si no aplica.
     * @param  CashBalanceCalculator    $balanceCalculator  Calculador de efectivo esperado (inyectado desde la Page).
     * @param  CashSessionService       $cashSessions       Servicio de caja (inyectado desde la Page).
     *
     * Los servicios se inyectan explícitamente desde la Page (que tiene DI via
     * boot()) en vez de resolverse con app() dentro de buildSchema/handle —
     * eso elimina el service locator antipattern y mejora testabilidad.
     */
    public static function make(
        Closure $sessionResolver,
        CashBalanceCalculator $balanceCalculator,
        CashSessionService $cashSessions,
    ): Action {
        return Action::make('closeCashSession')
            ->label('Cerrar mi caja')
            ->icon('heroicon-o-lock-closed')
            ->color('danger')
            ->visible(fn (): bool => $sessionResolver() !== null)
            ->modalHeading('Cerrar caja del día')
            ->modalDescription('Contá el efectivo físico en el cajón y registralo. Si el monto no coincide con lo esperado vas a necesitar la autorización de un administrador.')
            ->modalSubmitActionLabel('Cerrar caja')
            ->modalWidth('xl')
            ->schema(fn (): array => self::buildSchema($sessionResolver, $balanceCalculator))
            ->action(fn (array $data) => self::handle($sessionResolver, $cashSessions, $data));
    }

    /**
     * Schema reactivo del modal. Se resuelve al abrir (no al cargar la página)
     * para que `expectedCash` siempre refleje los movimientos actuales de la
     * sesión, no un snapshot viejo.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private static function buildSchema(Closure $sessionResolver, CashBalanceCalculator $balanceCalculator): array
    {
        $session = $sessionResolver();

        if ($session === null) {
            return [
                Placeholder::make('no_session')
                    ->label('')
                    ->content('No hay una sesión de caja abierta. Recargá la pantalla.'),
            ];
        }

        $expected = $balanceCalculator->expectedCash($session);
        $tolerance = (float) CompanySetting::current()->effectiveCashDiscrepancyTolerance();

        return [
            Placeholder::make('session_info')
                ->label('Sesión a cerrar')
                ->content(new HtmlString(sprintf(
                    '<div class="text-sm">Sesión <strong>#%d</strong> · %s · abierta por <strong>%s</strong> el %s</div>',
                    $session->id,
                    e($session->establishment->name ?? '—'),
                    e($session->openedBy->name ?? '—'),
                    $session->opened_at?->format('d/m/Y H:i') ?? '—',
                ))),

            Placeholder::make('expected_cash')
                ->label('Efectivo esperado en caja')
                ->content(new HtmlString(sprintf(
                    '<div class="text-lg font-bold text-gray-900 dark:text-white">L. %s</div>'
                    . '<div class="text-xs text-gray-500">Monto inicial + ingresos efectivo − egresos efectivo</div>',
                    number_format($expected, 2),
                ))),

            TextInput::make('actual_amount')
                ->label('Monto físico contado (Lempiras)')
                ->required()
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->live(onBlur: true)
                ->helperText('Contá el efectivo en el cajón y registralo sin redondear.')
                ->prefix('L'),

            Placeholder::make('discrepancy_preview')
                ->label('Descuadre calculado')
                ->content(function (Get $get) use ($expected, $tolerance): HtmlString {
                    return self::renderDiscrepancyPreview($get('actual_amount'), $expected, $tolerance);
                }),

            Textarea::make('notes')
                ->label('Observaciones')
                ->rows(3)
                ->maxLength(1000)
                ->helperText('Recomendado si hay descuadre: anotá por qué pensás que ocurrió.')
                ->required(function (Get $get) use ($expected): bool {
                    $actual = $get('actual_amount');
                    if ($actual === null || $actual === '') {
                        return false;
                    }

                    return round((float) $actual - $expected, 2) !== 0.0;
                }),

            Select::make('authorized_by_user_id')
                ->label('Autorizado por')
                ->helperText('Seleccioná al administrador que autoriza el cierre con descuadre.')
                ->options(fn (): array => self::authorizerOptions())
                ->searchable()
                ->preload()
                ->visible(fn (Get $get): bool => self::descuadreExcedeTolerancia($get('actual_amount'), $expected, $tolerance))
                ->required(fn (Get $get): bool => self::descuadreExcedeTolerancia($get('actual_amount'), $expected, $tolerance)),
        ];
    }

    /**
     * Handler del submit. Resuelve la sesión otra vez al ejecutar (defensa
     * contra race: pudo cerrarse entre el abrir-modal y el submit).
     *
     * @param  array<string, mixed>  $data
     */
    private static function handle(Closure $sessionResolver, CashSessionService $cashSessions, array $data): void
    {
        $session = $sessionResolver();

        if ($session === null) {
            Notification::make()
                ->title('No hay sesión abierta')
                ->body('La sesión fue cerrada por otro usuario. Recargá la pantalla.')
                ->warning()
                ->send();

            return;
        }

        $authorizedBy = isset($data['authorized_by_user_id']) && $data['authorized_by_user_id'] !== null
            ? User::find((int) $data['authorized_by_user_id'])
            : null;

        try {
            $closed = $cashSessions->close(
                session:             $session,
                closedBy:            auth()->user(),
                actualClosingAmount: (float) $data['actual_amount'],
                notes:               $data['notes'] ?? null,
                authorizedBy:        $authorizedBy,
            );

            $discrepancy = (float) $closed->discrepancy;
            $body = sprintf(
                'Sesión #%d cerrada. Monto contado: L. %s · Esperado: L. %s · Descuadre: L. %s',
                $closed->id,
                number_format((float) $closed->actual_closing_amount, 2),
                number_format((float) $closed->expected_closing_amount, 2),
                number_format($discrepancy, 2),
            );

            Notification::make()
                ->title($discrepancy === 0.0 ? 'Caja cerrada — cuadre exacto' : 'Caja cerrada con descuadre')
                ->body($body)
                ->success()
                ->send();
        } catch (DescuadreExcedeTolerancianException $e) {
            // El descuadre excede tolerancia y no hay autorizador. En teoría
            // la UI debería haber exigido el Select, pero la defensa en profundidad
            // vive aquí porque alguien podría bypass el reactive required.
            Notification::make()
                ->title('Descuadre excede tolerancia')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        } catch (MovimientoEnSesionCerradaException $e) {
            Notification::make()
                ->title('Sesión ya cerrada')
                ->body('Esta sesión fue cerrada por otro usuario mientras completabas el formulario. Recargá la pantalla.')
                ->warning()
                ->send();
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title('Error al cerrar caja')
                ->body('No se pudo completar el cierre. Revisá los logs y volvé a intentar.')
                ->danger()
                ->send();
        }
    }

    /**
     * Render HTML del Placeholder reactivo que muestra el descuadre estimado.
     * Se llama desde el closure del Placeholder, por eso está en una función
     * separada — simplifica testing y mantiene el schema legible.
     */
    private static function renderDiscrepancyPreview(mixed $actualRaw, float $expected, float $tolerance): HtmlString
    {
        if ($actualRaw === null || $actualRaw === '') {
            return new HtmlString(
                '<div class="text-sm text-gray-500">Ingresá el monto contado para ver el descuadre.</div>'
            );
        }

        $actual = (float) $actualRaw;
        $discrepancy = round($actual - $expected, 2);
        $exceedsTolerance = abs($discrepancy) > $tolerance;

        if ($discrepancy === 0.0) {
            return new HtmlString(
                '<div class="text-lg font-bold text-success-600 dark:text-success-400">'
                . 'L. 0.00 · Caja cuadrada exactamente</div>'
            );
        }

        $colorClass = $discrepancy > 0
            ? 'text-warning-600 dark:text-warning-400'
            : 'text-danger-600 dark:text-danger-400';
        $label = $discrepancy > 0 ? 'Sobra dinero' : 'Falta dinero';
        $toleranceMsg = $exceedsTolerance
            ? sprintf(
                '<div class="text-xs text-danger-600 mt-1">'
                . 'Supera la tolerancia de L. %s — se requiere autorización de un administrador.'
                . '</div>',
                number_format($tolerance, 2),
            )
            : '';

        return new HtmlString(sprintf(
            '<div class="text-lg font-bold %s">L. %s · %s</div>%s',
            $colorClass,
            number_format($discrepancy, 2),
            $label,
            $toleranceMsg,
        ));
    }

    /**
     * Helper del reactive visible/required del Select de autorizador.
     */
    private static function descuadreExcedeTolerancia(mixed $actualRaw, float $expected, float $tolerance): bool
    {
        if ($actualRaw === null || $actualRaw === '') {
            return false;
        }

        return abs(round((float) $actualRaw - $expected, 2)) > $tolerance;
    }

    /**
     * Usuarios elegibles para autorizar cierres con descuadre sobre tolerancia.
     *
     * D3b: `admin` y `super_admin` pueden firmar. Se excluye al user actual
     * porque auto-autorizarse un descuadre no tiene sentido de auditoría
     * (quien cuenta no firma su propio descuadre).
     *
     * @return array<int, string>  Map [user_id => "Nombre"]
     */
    private static function authorizerOptions(): array
    {
        $superAdminRole = Utils::getSuperAdminName();

        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [$superAdminRole, 'admin']))
            ->where('is_active', true)
            ->where('id', '!=', auth()->id())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (User $user) => [$user->id => $user->name])
            ->toArray();
    }
}
