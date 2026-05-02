<?php

namespace App\Notifications;

use App\Models\Repair;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifica al admin y cajero que una reparación está lista para entrega.
 *
 * Disparada por `RepairStatusService::marcarCompletada()` cuando el técnico
 * marca el equipo como completado. Permite al admin/cajero llamar al cliente
 * para coordinar la entrega.
 *
 * Canal: `database` (campana de Filament). Sin email — Mauricio descartó
 * email en F-R4 para no saturar el correo de Diproma con cada repair.
 *
 * Por qué Queueable: si el sistema crece y hay muchos admin/cajero, mandar
 * la notificación a todos puede tardar. Dejarlo en cola desacopla la UX
 * del técnico (su click "Marcar completada" debe responder rápido).
 */
class RepairCompletedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Repair $repair,
    ) {}

    /**
     * Canal: solo `database` (campana Filament).
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Payload guardado en `notifications.data` y leído por Filament para
     * pintar la campana. Filament usa el formato suyo, no el default de Laravel.
     */
    public function toDatabase(object $notifiable): array
    {
        $repair = $this->repair;
        $editUrl = route('filament.admin.resources.repairs.edit', ['record' => $repair->id]);

        return FilamentNotification::make()
            ->title('Reparación lista para entrega')
            ->body(sprintf(
                '%s — %s %s. Cliente: %s · %s',
                $repair->repair_number,
                $repair->device_brand,
                $repair->device_model ?? '',
                $repair->customer_name,
                $repair->customer_phone,
            ))
            ->icon('heroicon-o-bell-alert')
            ->iconColor('success')
            ->actions([
                Action::make('view')
                    ->label('Ver reparación')
                    ->url($editUrl)
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
