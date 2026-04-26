<?php

namespace App\Notifications;

use App\Filament\Resources\CaiRanges\CaiRangeResource;
use App\Models\CaiRange;
use App\Services\Invoicing\Exceptions\CaiSinSucesorException;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Notificación CRÍTICA: el failover automático intentó promover un sucesor
 * para uno o más CAIs inutilizables (vencidos o agotados) y no encontró
 * candidato válido — el POS queda bloqueado para ese alcance hasta que se
 * registre y active manualmente un nuevo CAI.
 *
 * Gemela conceptual de CaiExpiringNotification y CaiRangeExhaustingNotification
 * pero con eje temporal distinto:
 *
 *   - Expiring/Exhausting  → PREVENTIVAS. El CAI todavía funciona, todavía
 *                            hay margen para actuar. Severidad variable.
 *   - FailoverFailed       → POST-FALLA. El CAI ya está inutilizable Y no
 *                            hay relevo. SIEMPRE Critical. La acción
 *                            requerida es inmediata.
 *
 * Por eso va en clase dedicada en vez de reusar las existentes con flags.
 * Mezclar preventivo con post-falla vía `@if` en la plantilla vuelve ambas
 * plantillas frágiles a cambios independientes.
 *
 * Firma del constructor: `Collection<int, array{cai, exception}>` — exactamente
 * la forma del bucket `skippedNoSuccessor` del CaiFailoverReport. El Job
 * orquestador (F2.5) pasará el bucket directo sin transformación.
 */
class CaiFailoverFailedNotification extends Notification
{
    use Queueable;

    /**
     * @param  Collection<int, array{cai: CaiRange, exception: CaiSinSucesorException}>  $failures
     */
    public function __construct(
        public readonly Collection $failures,
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
        $count = $this->failures->count();

        return FilamentNotification::make()
            ->title($this->title($count))
            ->body($this->bodyLabels())
            ->icon('heroicon-o-exclamation-triangle')
            ->iconColor('danger')
            ->actions([
                \Filament\Notifications\Actions\Action::make('view')
                    ->label('Gestionar CAIs')
                    ->url(CaiRangeResource::getUrl('index'))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = $this->failures->count();

        $mail = (new MailMessage)
            ->error() // pinta el botón de acción en rojo (tono crítico)
            ->subject("[CRÍTICO] Sistema Diproma · Failover de CAI falló — {$count} CAI(s) bloqueado(s)")
            ->greeting("Hola {$notifiable->name},")
            ->line($this->title($count))
            ->line('Detalle de los CAIs sin sucesor disponible:');

        foreach ($this->failures as $failure) {
            $mail->line('• '.$this->failureLabel($failure));
        }

        return $mail
            ->line('**Acción requerida inmediata:** solicite un nuevo CAI al SAR, '
                .'regístrelo en el módulo de Administración y actívelo manualmente. '
                .'Mientras tanto, no será posible emitir documentos del tipo afectado '
                .'en la(s) sucursal(es) listada(s).')
            ->action('Gestionar CAIs', CaiRangeResource::getUrl('index', panel: 'admin'))
            ->line('Esta alerta proviene del mecanismo de failover automático. Se '
                .'repetirá cada mañana hasta que se resuelva el bloqueo.');
    }

    private function title(int $count): string
    {
        return $count === 1
            ? '[CRÍTICO] 1 CAI quedó bloqueado sin sucesor disponible'
            : "[CRÍTICO] {$count} CAIs quedaron bloqueados sin sucesor disponible";
    }

    private function bodyLabels(): string
    {
        return $this->failures
            ->map(fn (array $failure) => $this->failureLabel($failure))
            ->implode(' · ');
    }

    /**
     * Construye una línea legible por humano para cada fallo.
     *
     * Ej: "CAI 01 · establecimiento #1 · A1B2C3-D4E5F6 · vencido"
     *
     * @param  array{cai: CaiRange, exception: CaiSinSucesorException}  $failure
     */
    private function failureLabel(array $failure): string
    {
        /** @var CaiRange $cai */
        $cai = $failure['cai'];
        /** @var CaiSinSucesorException $exception */
        $exception = $failure['exception'];

        $alcance = $cai->establishment_id
            ? "establecimiento #{$cai->establishment_id}"
            : 'empresa (centralizado)';

        $motivo = match ($exception->reason) {
            CaiSinSucesorException::REASON_EXPIRED => 'vencido',
            CaiSinSucesorException::REASON_EXHAUSTED => 'agotado',
            default => $exception->reason,
        };

        return sprintf(
            'CAI %s · %s · %s · %s',
            $cai->document_type,
            $alcance,
            $cai->cai,
            $motivo,
        );
    }
}
