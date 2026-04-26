<?php

namespace App\Notifications;

use App\Filament\Resources\Cash\CashSessionResource;
use App\Models\CashSession;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notificación: una sesión de caja fue cerrada automáticamente por el sistema.
 *
 * Disparada por `AutoCloseCashSessionsJob` para cada sesión que el job auto-cerró.
 * Destinatarios resueltos por el job:
 *   - El cajero que abrió la sesión (debe conciliar al regresar).
 *   - Admins/super_admin activos (visibilidad operacional).
 *
 * Canales:
 *   - `database` (campanita Filament) — siempre, para que el cajero la vea
 *     apenas entre al panel a la mañana siguiente.
 *   - `mail` — solo si el destinatario tiene `email_verified_at`. Útil para
 *     cuando el cajero no abre el panel hasta el día siguiente pero revisa
 *     mail desde el celular.
 *
 * Tono:
 *   - No es alarma — el auto-cierre es un mecanismo de protección, no un error.
 *   - El cuerpo enfatiza la acción esperada: "conciliá el conteo apenas regreses".
 *   - El link lleva al view de la sesión para que pueda iniciar el flujo de
 *     conciliación desde ahí.
 *
 * Inmutable: la notificación se construye con los valores de la sesión EN
 * EL MOMENTO del auto-cierre. Si el cajero concilia mañana y la sesión cambia,
 * la notificación ya enviada NO se actualiza — sería ruido. La campanita y
 * el mail son snapshots históricos.
 */
class CashSessionAutoClosedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly CashSession $session,
        public readonly string $establishmentName,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (! empty($notifiable->email_verified_at ?? null)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Tu caja fue cerrada automáticamente')
            ->body($this->bodyShort())
            ->icon('heroicon-o-clock')
            ->iconColor('warning')
            ->actions([
                FilamentAction::make('reconcile')
                    ->label('Ver y conciliar')
                    ->url(CashSessionResource::getUrl('view', ['record' => $this->session->id]))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expected = number_format((float) $this->session->expected_closing_amount, 2);

        return (new MailMessage())
            ->subject("Sistema Diproma · Caja auto-cerrada — sucursal {$this->establishmentName}")
            ->greeting("Hola {$notifiable->name},")
            ->line('El sistema cerró automáticamente tu sesión de caja porque quedó abierta '
                . 'al final del día. Esto es una protección operativa — no afecta los movimientos '
                . 'ya registrados.')
            ->line("Sucursal: {$this->establishmentName}")
            ->line("Sesión #{$this->session->id} · abierta el "
                . $this->session->opened_at->format('d/m/Y H:i'))
            ->line("Saldo esperado al cierre (calculado): L. {$expected}")
            ->line('Cuando regreses, ingresá el conteo físico real del cajón para conciliar la sesión '
                . 'y poder abrir una nueva. Tenés 7 días antes de que el sistema bloquee abrir caja '
                . 'nueva en esta sucursal.')
            ->action('Ir a la sesión', CashSessionResource::getUrl('view', ['record' => $this->session->id], panel: 'admin'))
            ->line('Si pensás que esto fue un error, contactá al administrador.');
    }

    /**
     * Cuerpo corto para la campanita de Filament (espacio limitado).
     */
    private function bodyShort(): string
    {
        $expected = number_format((float) $this->session->expected_closing_amount, 2);

        return "Sucursal {$this->establishmentName} · Esperado L. {$expected} · "
            . 'Conciliá apenas regreses (tenés 7 días antes de que se bloquee abrir caja nueva).';
    }
}
