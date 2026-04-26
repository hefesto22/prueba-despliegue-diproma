<?php

namespace App\Notifications;

use App\Filament\Resources\CaiRanges\CaiRangeResource;
use App\Services\Alerts\DTOs\CaiExpirationAlert;
use App\Services\Alerts\Enums\CaiAlertSeverity;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Notificación de CAIs próximos a vencer.
 *
 * Se envía a usuarios con permiso `Manage:Cai` desde SendCaiAlertsJob.
 *
 * Canales:
 *   - `database` (campanita de Filament) — siempre
 *   - `mail` — solo si el usuario tiene `email_verified_at`
 *
 * El color/tono de la notificación se decide por la MAYOR severidad presente
 * en la colección: si hay al menos una alerta Critical, toda la notificación
 * se presenta como peligro; si solo hay Urgent, como warning; solo Info → info.
 * La racional es que el usuario debe reaccionar al peor caso visible, no
 * promediar severidades.
 *
 * La colección llega pre-ordenada cronológicamente por el checker (ORDER BY
 * expiration_date ASC) — lo que vence primero aparece primero en el cuerpo.
 */
class CaiExpiringNotification extends Notification
{
    use Queueable;

    /**
     * @param  Collection<int, CaiExpirationAlert>  $alerts
     */
    public function __construct(
        public readonly Collection $alerts,
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
        $severity = $this->maxSeverity();
        $count = $this->alerts->count();

        return FilamentNotification::make()
            ->title($this->title($count, $severity))
            ->body($this->bodyLabels())
            ->icon('heroicon-o-clock')
            ->iconColor($severity->filamentColor())
            ->actions([
                \Filament\Notifications\Actions\Action::make('view')
                    ->label('Ver CAIs')
                    ->url(CaiRangeResource::getUrl('index'))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $severity = $this->maxSeverity();
        $count = $this->alerts->count();
        $subject = $severity === CaiAlertSeverity::Critical
            ? "[CRÍTICO] Sistema Diproma · {$count} CAI(s) próximos a vencer"
            : "Sistema Diproma · {$count} CAI(s) próximos a vencer";

        $mail = (new MailMessage())
            ->subject($subject)
            ->greeting("Hola {$notifiable->name},")
            ->line($this->title($count, $severity));

        // Una línea por alerta para que el contador vea el detalle de cada CAI
        // sin tener que abrir el panel. Útil especialmente en móvil.
        foreach ($this->alerts as $alert) {
            $mail->line('• ' . $alert->shortLabel());
        }

        if ($this->hasAlertsWithoutSuccessor()) {
            $mail->line('⚠️ Hay CAIs sin sucesor pre-registrado. Solicita el siguiente CAI al SAR '
                . 'y regístralo en el sistema ANTES del vencimiento para evitar cortar la operación.');
        } else {
            $mail->line('Todos los CAIs en alerta tienen sucesor pre-registrado — cuando el actual '
                . 'venza, el sistema podrá promover el siguiente.');
        }

        return $mail
            ->action('Ir al módulo CAI', CaiRangeResource::getUrl('index', panel: 'admin'))
            ->line('Esta alerta se genera automáticamente cada mañana — dejará de llegarte una vez '
                . 'resueltos los vencimientos pendientes.');
    }

    /**
     * Severidad máxima presente en la colección (Critical > Urgent > Info).
     */
    private function maxSeverity(): CaiAlertSeverity
    {
        return $this->alerts
            ->map(fn (CaiExpirationAlert $a) => $a->severity)
            ->sortByDesc(fn (CaiAlertSeverity $s) => $s->weight())
            ->first() ?? CaiAlertSeverity::Info;
    }

    private function title(int $count, CaiAlertSeverity $severity): string
    {
        $prefix = $severity === CaiAlertSeverity::Critical ? '[CRÍTICO] ' : '';

        return $count === 1
            ? "{$prefix}Tienes 1 CAI próximo a vencer"
            : "{$prefix}Tienes {$count} CAIs próximos a vencer";
    }

    private function bodyLabels(): string
    {
        return $this->alerts
            ->map(fn (CaiExpirationAlert $a) => $a->shortLabel())
            ->implode(' · ');
    }

    private function hasAlertsWithoutSuccessor(): bool
    {
        return $this->alerts->contains(fn (CaiExpirationAlert $a) => ! $a->hasSuccessor);
    }
}
