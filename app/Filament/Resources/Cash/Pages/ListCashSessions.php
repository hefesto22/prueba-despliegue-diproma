<?php

namespace App\Filament\Resources\Cash\Pages;

use App\Exceptions\Cash\CajaYaAbiertaException;
use App\Filament\Resources\Cash\Actions\CloseCashSessionAction;
use App\Filament\Resources\Cash\Actions\RecordExpenseAction;
use App\Filament\Resources\Cash\CashSessionResource;
use App\Models\CashSession;
use App\Services\Cash\CashBalanceCalculator;
use App\Services\Cash\CashSessionService;
use App\Services\Establishments\EstablishmentResolver;
use App\Services\Establishments\Exceptions\NoActiveEstablishmentException;
use App\Services\Expenses\ExpenseService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

/**
 * Listado de sesiones de caja con header actions de ciclo de vida.
 *
 * Actions expuestas (mutuamente excluyentes según estado de la sucursal):
 *   - "Abrir caja del día"  → visible si NO hay sesión abierta.
 *   - "Cerrar mi caja"      → visible si SÍ hay sesión abierta en la sucursal.
 *
 * La close action se construye desde `CloseCashSessionAction::make()` — la
 * misma clase se reutiliza en `ViewCashSession` para no duplicar schema ni
 * handler de excepciones.
 *
 * Decisiones de diseño:
 *   - D2b: monto inicial pre-llenado con `actual_closing_amount` de la última
 *     sesión cerrada — reduce errores de digitación en el día a día.
 */
class ListCashSessions extends ListRecords
{
    protected static string $resource = CashSessionResource::class;

    /**
     * Servicios inyectados via boot() — Livewire 3 soporta method injection en
     * boot(). Propiedades protected para que NO se serialicen entre requests
     * (solo las public lo hacen) — el container resuelve fresh cada render y
     * mantiene intacto el memo interno del EstablishmentResolver.
     */
    protected EstablishmentResolver $establishments;

    protected CashSessionService $cashSessions;

    protected CashBalanceCalculator $balanceCalculator;

    protected ExpenseService $expenses;

    public function boot(
        EstablishmentResolver $establishments,
        CashSessionService $cashSessions,
        CashBalanceCalculator $balanceCalculator,
        ExpenseService $expenses,
    ): void {
        $this->establishments = $establishments;
        $this->cashSessions = $cashSessions;
        $this->balanceCalculator = $balanceCalculator;
        $this->expenses = $expenses;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openCashSession')
                ->label('Abrir caja del día')
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->visible(fn (): bool => $this->canOpenCashSession())
                ->modalHeading('Abrir caja del día')
                ->modalDescription('Cuenta el efectivo físico en el cajón y captura el monto inicial. Quedará registrada una apertura con tu nombre y la hora actual.')
                ->modalSubmitActionLabel('Abrir caja')
                ->schema([
                    TextInput::make('opening_amount')
                        ->label('Monto inicial en caja (Lempiras)')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->default(fn (): float => $this->suggestedOpeningAmount())
                        ->helperText('Sugerido: monto de cierre de la última sesión. Ajustá si contás distinto.')
                        ->prefix('L'),
                ])
                ->action(function (array $data): void {
                    $this->openSession((float) $data['opening_amount']);
                }),

            // Cerrar caja — schema, validación reactiva y manejo de excepciones
            // viven en CloseCashSessionAction. Acá inyectamos el resolver de
            // sesión y los services que la action necesita (sin app()).
            CloseCashSessionAction::make(
                fn (): ?CashSession => $this->currentOpenSession(),
                $this->balanceCalculator,
                $this->cashSessions,
            ),

            // Registrar gasto — visible solo con caja abierta (de ahí toma el
            // establishment_id). El ExpenseService decide si crea CashMovement
            // según el payment_method elegido en el form.
            RecordExpenseAction::make(
                fn (): ?CashSession => $this->currentOpenSession(),
                $this->expenses,
            ),
        ];
    }

    /**
     * Sólo mostrar el botón "Abrir" si el user puede resolver una sucursal
     * activa y NO hay sesión abierta en ella. Evita el caso "abro y me sale
     * error".
     *
     * Si la resolución falla (user sin matriz/default), oculto la action y
     * dejo que el banner del switcher de sucursal guíe al user.
     */
    private function canOpenCashSession(): bool
    {
        try {
            $establishment = $this->establishments->resolve();
        } catch (NoActiveEstablishmentException) {
            return false;
        }

        return CashSession::query()
            ->where('establishment_id', $establishment->id)
            ->whereNull('closed_at')
            ->doesntExist();
    }

    /**
     * Pre-llenado del monto inicial (D2b): último `actual_closing_amount`
     * de la sucursal. Si nunca se cerró una sesión, default a 0.
     *
     * Index en cash_sessions (closed_at desc + establishment_id) hace esto O(log n).
     */
    private function suggestedOpeningAmount(): float
    {
        try {
            $establishment = $this->establishments->resolve();
        } catch (NoActiveEstablishmentException) {
            return 0.0;
        }

        $lastClosing = CashSession::query()
            ->where('establishment_id', $establishment->id)
            ->whereNotNull('closed_at')
            ->orderByDesc('closed_at')
            ->value('actual_closing_amount');

        return $lastClosing !== null ? (float) $lastClosing : 0.0;
    }

    /**
     * Resuelve la sesión abierta actual de la sucursal del user, o null si no
     * hay ninguna. Usada por la CloseCashSessionAction.
     */
    private function currentOpenSession(): ?CashSession
    {
        try {
            $establishment = $this->establishments->resolve();
        } catch (NoActiveEstablishmentException) {
            return null;
        }

        return $this->cashSessions->currentOpenSession($establishment->id);
    }

    private function openSession(float $openingAmount): void
    {
        try {
            $establishment = $this->establishments->resolve();
        } catch (NoActiveEstablishmentException $e) {
            Notification::make()
                ->title('No tenés sucursal activa')
                ->body('Pedile a un administrador que te asigne una sucursal antes de abrir caja.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        try {
            $session = $this->cashSessions->open(
                establishmentId: $establishment->id,
                openedBy:        auth()->user(),
                openingAmount:   $openingAmount,
            );

            Notification::make()
                ->title('Caja abierta')
                ->body("Sesión #{$session->id} en {$establishment->name} con monto inicial L. " . number_format($openingAmount, 2))
                ->success()
                ->send();
        } catch (CajaYaAbiertaException $e) {
            // Race condition defensiva: alguien abrió caja entre el check
            // de visibilidad y el submit. Mostrar mensaje claro y refrescar.
            Notification::make()
                ->title('Ya hay una caja abierta')
                ->body('Otra sesión fue abierta en esta sucursal. Recargá la pantalla para verla.')
                ->warning()
                ->send();
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title('Error al abrir caja')
                ->body('No se pudo abrir la sesión. Revisá los logs y volvé a intentar.')
                ->danger()
                ->send();
        }
    }
}
