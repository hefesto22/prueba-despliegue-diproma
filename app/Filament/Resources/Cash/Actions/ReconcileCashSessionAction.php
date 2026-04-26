<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cash\Actions;

use App\Exceptions\Cash\DescuadreExcedeTolerancianException;
use App\Exceptions\Cash\MovimientoEnSesionCerradaException;
use App\Models\CashSession;
use App\Models\CompanySetting;
use App\Models\User;
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
 * Action de "Conciliar sesión auto-cerrada".
 *
 * Cuando `AutoCloseCashSessionsJob` cierra una sesión por inactividad, queda
 * con `closed_at` y `expected_closing_amount` seteados pero `actual_closing_amount`,
 * `discrepancy` y `closed_by_user_id` en NULL — el sistema no contó plata. Esta
 * action permite a un humano (cajero o admin) ingresar el conteo físico tardío
 * y completar el ciclo de auditoría.
 *
 * Comparada con `CloseCashSessionAction`:
 *   - Misma regla de tolerancia + autorización (D3b).
 *   - Misma UX reactiva (preview del descuadre + Select condicional de autorizador).
 *   - Diferente service method: `reconcile()` en vez de `close()`.
 *   - Diferente fuente del `expected`: leído del modelo (ya persistido por
 *     `closeBySystem()`) en vez de recalcular en UI. El service recalcula al
 *     persistir como defensa en profundidad — la fuente de verdad sigue siendo
 *     el service, no la pantalla.
 *
 * Visibilidad:
 *   - El `$sessionResolver` debe retornar la sesión SOLO si está cerrada Y
 *     pendiente de conciliación. Eso lo enforce quien crea la action (la tabla
 *     y la View page).
 *
 * NO se ocupa de:
 *   - Validar permisos (Policy `update` sobre CashSession lo cubre).
 *   - Cálculos (delegados a CashSessionService).
 */
final class ReconcileCashSessionAction
{
    /**
     * @param  Closure(?CashSession=null): ?CashSession  $sessionResolver
     *         Retorna la sesión a conciliar o null si no aplica. Recibe el
     *         `$record` que Filament inyecta cuando la action vive como row
     *         action (en `CashSessionsTable::recordActions`); en header actions
     *         de `ViewCashSession`, Filament no inyecta nada y el resolver
     *         decide a partir del state de la Page (`$this->record`).
     * @param  CashSessionService  $cashSessions  Servicio de caja.
     *
     * Por qué el resolver acepta `?CashSession` y no es `Closure(): ?CashSession`
     * como en `CloseCashSessionAction`: aquella vive solo en header actions
     * (donde el record está en el scope al definir el closure). Esta vive
     * además como row action de la tabla, donde el record solo se conoce al
     * ejecutar — Filament lo inyecta en los callbacks de la action y lo
     * propagamos al resolver.
     */
    public static function make(
        Closure $sessionResolver,
        CashSessionService $cashSessions,
    ): Action {
        return Action::make('reconcileCashSession')
            ->label('Conciliar')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('warning')
            ->visible(fn (?CashSession $record = null): bool => $sessionResolver($record) !== null)
            ->modalHeading('Conciliar sesión auto-cerrada')
            ->modalDescription('Esta sesión fue cerrada por el sistema por inactividad. Contá el efectivo físico que quedó en el cajón al momento del cierre y registrá el monto para completar la auditoría.')
            ->modalSubmitActionLabel('Conciliar')
            ->modalWidth('xl')
            ->schema(fn (?CashSession $record = null): array => self::buildSchema($sessionResolver, $record))
            ->action(fn (array $data, ?CashSession $record = null) => self::handle($sessionResolver, $cashSessions, $data, $record));
    }

    /**
     * Schema reactivo del modal. Se resuelve al abrir el modal — si la sesión
     * fue conciliada por otro user en el ínterin, el `visible()` ya la habrá
     * ocultado, pero el guard de `null` cubre el caso edge.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private static function buildSchema(Closure $sessionResolver, ?CashSession $record = null): array
    {
        $session = $sessionResolver($record);

        if ($session === null) {
            return [
                Placeholder::make('no_session')
                    ->label('')
                    ->content('No hay una sesión pendiente de conciliación. Recargá la pantalla.'),
            ];
        }

        // expected_closing_amount fue persistido por closeBySystem(). Lo leemos
        // directamente — el service recalcula al ejecutar reconcile() (defensa en
        // profundidad), pero para el preview reactivo basta con el valor guardado.
        $expected = (float) ($session->expected_closing_amount ?? 0.0);
        $tolerance = (float) CompanySetting::current()->effectiveCashDiscrepancyTolerance();

        return [
            Placeholder::make('session_info')
                ->label('Sesión a conciliar')
                ->content(new HtmlString(sprintf(
                    '<div class="text-sm">'
                    . 'Sesión <strong>#%d</strong> · %s · abierta por <strong>%s</strong> el %s'
                    . '<br><span class="text-xs text-gray-500">Auto-cerrada por el sistema el %s</span>'
                    . '</div>',
                    $session->id,
                    e($session->establishment->name ?? '—'),
                    e($session->openedBy->name ?? '—'),
                    $session->opened_at?->format('d/m/Y H:i') ?? '—',
                    $session->closed_by_system_at?->format('d/m/Y H:i') ?? '—',
                ))),

            Placeholder::make('expected_cash')
                ->label('Efectivo esperado al cierre')
                ->content(new HtmlString(sprintf(
                    '<div class="text-lg font-bold text-gray-900 dark:text-white">L. %s</div>'
                    . '<div class="text-xs text-gray-500">Calculado por el sistema al auto-cerrar (apertura + ingresos efectivo − egresos efectivo)</div>',
                    number_format($expected, 2),
                ))),

            TextInput::make('actual_amount')
                ->label('Monto físico contado (Lempiras)')
                ->required()
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->live(onBlur: true)
                ->helperText('Contá el efectivo que quedó en el cajón cuando se auto-cerró la sesión y registralo sin redondear.')
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
                ->helperText('Recomendado si hay descuadre: anotá por qué pensás que ocurrió o cuándo se hizo el conteo físico.')
                ->required(function (Get $get) use ($expected): bool {
                    $actual = $get('actual_amount');
                    if ($actual === null || $actual === '') {
                        return false;
                    }

                    return round((float) $actual - $expected, 2) !== 0.0;
                }),

            Select::make('authorized_by_user_id')
                ->label('Autorizado por')
                ->helperText('Seleccioná al administrador que autoriza la conciliación con descuadre.')
                ->options(fn (): array => self::authorizerOptions())
                ->searchable()
                ->preload()
                ->visible(fn (Get $get): bool => self::descuadreExcedeTolerancia($get('actual_amount'), $expected, $tolerance))
                ->required(fn (Get $get): bool => self::descuadreExcedeTolerancia($get('actual_amount'), $expected, $tolerance)),
        ];
    }

    /**
     * Handler del submit. Resuelve la sesión otra vez al ejecutar (defensa
     * contra race: pudo haber sido conciliada entre abrir-modal y submit).
     *
     * @param  array<string, mixed>  $data
     */
    private static function handle(Closure $sessionResolver, CashSessionService $cashSessions, array $data, ?CashSession $record = null): void
    {
        $session = $sessionResolver($record);

        if ($session === null) {
            Notification::make()
                ->title('No hay sesión pendiente')
                ->body('La sesión fue conciliada por otro usuario. Recargá la pantalla.')
                ->warning()
                ->send();

            return;
        }

        $authorizedBy = isset($data['authorized_by_user_id']) && $data['authorized_by_user_id'] !== null
            ? User::find((int) $data['authorized_by_user_id'])
            : null;

        try {
            $reconciled = $cashSessions->reconcile(
                session:             $session,
                reconciledBy:        auth()->user(),
                actualClosingAmount: (float) $data['actual_amount'],
                notes:               $data['notes'] ?? null,
                authorizedBy:        $authorizedBy,
            );

            $discrepancy = (float) $reconciled->discrepancy;
            $body = sprintf(
                'Sesión #%d conciliada. Monto contado: L. %s · Esperado: L. %s · Descuadre: L. %s',
                $reconciled->id,
                number_format((float) $reconciled->actual_closing_amount, 2),
                number_format((float) $reconciled->expected_closing_amount, 2),
                number_format($discrepancy, 2),
            );

            Notification::make()
                ->title($discrepancy === 0.0 ? 'Conciliación exacta' : 'Conciliación con descuadre')
                ->body($body)
                ->success()
                ->send();
        } catch (DescuadreExcedeTolerancianException $e) {
            // Descuadre supera tolerancia y faltó autorizador. Defensa en
            // profundidad: la UI ya hace el campo Select required cuando aplica,
            // pero alguien podría bypass el reactive required.
            Notification::make()
                ->title('Descuadre excede tolerancia')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        } catch (MovimientoEnSesionCerradaException $e) {
            // El service usa esta excepción para "ya conciliada" o "no estaba
            // pendiente". Mensaje genérico — recargar resuelve ambos casos.
            Notification::make()
                ->title('Sesión no disponible para conciliar')
                ->body('Esta sesión ya fue conciliada o cambió de estado mientras completabas el formulario. Recargá la pantalla.')
                ->warning()
                ->send();
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title('Error al conciliar')
                ->body('No se pudo completar la conciliación. Revisá los logs y volvé a intentar.')
                ->danger()
                ->send();
        }
    }

    /**
     * Render HTML del Placeholder reactivo que muestra el descuadre estimado.
     * Idéntico al de CloseCashSessionAction — extraído acá para mantener la
     * action autocontenida.
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
                . 'L. 0.00 · Cuadre exacto</div>'
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
     * Usuarios elegibles para autorizar conciliaciones con descuadre sobre
     * tolerancia. Misma regla que `CloseCashSessionAction::authorizerOptions()`:
     * `admin` y `super_admin`, excluyendo al user actual.
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
