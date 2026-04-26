<?php

namespace App\Notifications;

use App\Filament\Resources\CaiRanges\CaiRangeResource;
use App\Services\Alerts\DTOs\CaiRangeExhaustionAlert;
use App\Services\Alerts\Enums\CaiAlertSeverity;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Notificación de CAIs cuyo rango de correlativos se está agotando.
 *
 * Gemela de CaiExpiringNotification pero con eje distinto: aquí el riesgo
 * es quedarse SIN números de factura, no sin vigencia temporal.
 *
 * Se mantiene como notificación separada (en vez de unificar con
 * CaiExpiringNotification) porque:
 *   1. La acción de remediación suele ser distinta (pre-registrar un CAI
 *      con rango nuevo vs. solicitar renovación al SAR).
 *   2. Los umbrales son independientes (vigencia en días, volumen en %).
 *   3. Un CAI puede tener agotamiento inminente sin estar cerca de vencer
 *      temporalmente, y viceversa — mezclarlos confunde la lectura.
 */
class CaiRangeExhaustingNotification extends Notification
{
    use Queueable;

    /**
     * @param  Collection<int, CaiRangeExhaustionAlert>  $alerts
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
            ->icon('heroicon-o-chart-bar-square')
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
            ? "[CRÍTICO] Sistema Diproma · {$count} CAI(s) cercanos a agotarse"
            : "Sistema Diproma · {$count} CAI(s) cercanos a agotarse";

        $mail = (new MailMessage())
            ->subject($subject)
            ->greeting("Hola {$notifiable->name},")
            ->line($this->title($count, $severity));

        foreach ($this->alerts as $alert) {
            $mail->line('• ' . $alert->shortLabel());
        }

        if ($this->hasAlertsWithoutSuccessor()) {
            $mail->line('⚠️ Hay CAIs sin sucesor pre-registrado. Solicita un CAI adicional al SAR '
                . 'y regístralo como siguiente ANTES de que se agoten los correlativos actuales — '
                . 'de lo contrario no será posible emitir más facturas de ese tipo.');
        } else {
            $mail->line('Todos los CAIs en alerta tienen sucesor pre-registrado — cuando el actual '
                . 'se agote, el sistema podrá promover el siguiente.');
        }

        return $mail
            ->action('Ir al módulo CAI', CaiRangeResource::getUrl('index', panel: 'admin'))
            ->line('Esta alerta se genera automáticamente cada mañana — dejará de llegarte una vez '
                . 'resuelto el agotamiento.');
    }

    private function maxSeverity(): CaiAlertSeverity
    {
        return $this->alerts
            ->map(fn (CaiRangeExhaustionAlert $a) => $a->severity)
            ->sortByDesc(fn (CaiAlertSeverity $s) => $s->weight())
            ->first() ?? CaiAlertSeverity::Info;
    }

    private function title(int $count, CaiAlertSeverity $severity): string
    {
        $prefix = $severity === CaiAlertSeverity::Critical ? '[CRÍTICO] ' : '';

        return $count === 1
            ? "{$prefix}Tienes 1 CAI cercano a agotarse"
            : "{$prefix}Tienes {$count} CAIs cercanos a agotarse";
    }

    private function bodyLabels(): string
    {
        return $this->alerts
            ->map(fn (CaiRangeExhaustionAlert $a) => $a->shortLabel())
            ->implode(' · ');
    }

    private function hasAlertsWithoutSuccessor(): bool
    {
        return $this->alerts->contains(fn (CaiRangeExhaustionAlert $a) => ! $a->hasSuccessor);
    }
}
