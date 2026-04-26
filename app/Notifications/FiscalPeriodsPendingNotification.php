<?php

namespace App\Notifications;

use App\Filament\Resources\FiscalPeriods\FiscalPeriodResource;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Notificación de períodos fiscales pendientes de declarar.
 *
 * Se envía a usuarios con permiso `Declare:FiscalPeriod` desde
 * SendFiscalPeriodAlertsJob. Canales:
 *   - `database` (campanita de Filament) — siempre
 *   - `mail` — solo si el usuario tiene email_verified_at
 *
 * Usa FilamentNotification para el canal database para que se renderice con
 * el estilo del panel (color + action url) en vez del formato default de Laravel.
 *
 * La colección de períodos se inyecta por constructor y se serializa dentro
 * del payload database como un array de labels — no guardamos IDs porque el
 * listado es informativo, no transaccional.
 */
class FiscalPeriodsPendingNotification extends Notification
{
    use Queueable;

    /**
     * @param Collection<int, \App\Models\FiscalPeriod> $periods Ordenados cronológicamente ASC.
     */
    public function __construct(
        public readonly Collection $periods,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (! empty($notifiable->email_verified_at ?? null)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Canal database: usa el formato de FilamentNotification para que la
     * campanita del panel muestre el mensaje con el mismo estilo que
     * las notificaciones nativas de Filament.
     */
    public function toDatabase(object $notifiable): array
    {
        $count = $this->periods->count();
        $labels = $this->periodLabels();

        return FilamentNotification::make()
            ->title($this->title($count))
            ->body($labels)
            ->icon('heroicon-o-exclamation-triangle')
            ->iconColor($count >= 2 ? 'danger' : 'warning')
            ->actions([
                \Filament\Notifications\Actions\Action::make('view')
                    ->label('Ver Declaraciones ISV')
                    ->url(FiscalPeriodResource::getUrl('index'))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = $this->periods->count();

        return (new MailMessage())
            ->subject("Sistema Diproma · {$count} período(s) fiscal(es) sin declarar")
            ->greeting("Hola {$notifiable->name},")
            ->line($this->title($count))
            ->line("Períodos pendientes: {$this->periodLabels()}")
            ->line('Recuerda que el ISV se presenta antes del día 10 del mes siguiente al período. '
                . 'Declararlo tarde genera mora y recargos ante el SAR.')
            ->action('Ir a Declaraciones ISV', FiscalPeriodResource::getUrl('index', panel: 'admin'))
            ->line('Esta alerta se genera automáticamente — puedes ignorarla una vez declarados los períodos.');
    }

    private function title(int $count): string
    {
        return $count === 1
            ? 'Tienes 1 período fiscal sin declarar'
            : "Tienes {$count} períodos fiscales sin declarar";
    }

    private function periodLabels(): string
    {
        return $this->periods
            ->map(fn ($period) => $period->period_label)
            ->implode(' · ');
    }
}
