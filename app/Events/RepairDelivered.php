<?php

namespace App\Events;

use App\Models\Repair;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de dominio: una Reparación fue entregada al cliente.
 *
 * Emitido por `RepairDeliveryService` AL FINAL de la transacción atómica
 * (después del commit). Listeners registrados:
 *   - F-R6: programa el borrado físico de las fotos del equipo a los 7 días.
 *   - Auditoría: queda registrado vía `repair_status_logs::StatusChange`.
 *
 * Disparar el evento DENTRO de la transacción acoplaría los listeners al
 * éxito/rollback del commit. Disparar DESPUÉS garantiza que solo se notifica
 * si la entrega quedó persistida.
 */
class RepairDelivered
{
    use Dispatchable;

    public function __construct(
        public readonly Repair $repair,
    ) {}
}
