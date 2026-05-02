<?php

namespace App\Services\Repairs;

use App\Enums\CashMovementType;
use App\Enums\CustomerCreditSource;
use App\Enums\PaymentMethod;
use App\Enums\RepairLogEvent;
use App\Enums\RepairStatus;
use App\Exceptions\Cash\NoHayCajaAbiertaException;
use App\Exceptions\Repairs\RepairTransitionException;
use App\Models\CustomerCredit;
use App\Models\Repair;
use App\Models\User;
use App\Notifications\RepairCompletedNotification;
use App\Services\Cash\CashSessionService;
use App\Services\Establishments\EstablishmentResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Orquestador de transiciones de estado de una Reparación.
 *
 * Responsabilidades:
 *   - Aplicar reglas de transición usando `RepairStatus::canTransitionTo()`
 *     como guardián. Si una transición no está en la lista blanca, lanza
 *     `RepairTransitionException` (fail-fast con contexto).
 *   - Persistir el cambio de estado + el timestamp denormalizado correspondiente
 *     (quoted_at, approved_at, completed_at, etc.) en la misma transacción.
 *   - Registrar cada cambio en `repair_status_logs` con metadata estructurada
 *     (event_type, from/to, changed_by, contexto del evento).
 *   - Coordinar efectos colaterales atómicos: anticipos en caja, conversión
 *     de anticipo a crédito, devolución de anticipo.
 *
 * Lo que NO hace:
 *   - Editar items / recalcular totales (eso es `RepairQuotationService`).
 *   - Generar factura CAI / descontar stock al entregar (eso es F-R5
 *     `RepairDeliveryService` — entregar tiene tantos efectos colaterales
 *     que merece su propio service).
 *   - Disparar notificaciones (las dispara el caller / event listener;
 *     este service solo registra los cambios de estado en el log).
 *
 * Atomicidad: cada método público envuelve su lógica en `DB::transaction`.
 * Si falla cualquier paso (validación, persistencia, registro de movimiento
 * de caja), todo rollback — Repair no queda en estado inconsistente.
 */
class RepairStatusService
{
    public function __construct(
        private readonly CashSessionService $cashSessionService,
        private readonly EstablishmentResolver $establishmentResolver,
    ) {}

    /**
     * Marcar una reparación como Cotizada.
     *
     * Pre-condiciones:
     *   - Estado actual = Recibido.
     *   - La reparación tiene al menos una línea en `repair_items`.
     *   - El diagnóstico no es opcional (debe estar lleno antes de cotizar).
     */
    public function cotizar(Repair $repair, ?string $note = null): Repair
    {
        $this->assertCanTransitionTo($repair, RepairStatus::Cotizado);

        if ($repair->items()->count() === 0) {
            throw new \DomainException(
                'No se puede cotizar una reparación sin líneas. Agrega al menos honorarios o piezas.'
            );
        }

        if (blank($repair->diagnosis)) {
            throw new \DomainException(
                'El diagnóstico técnico es obligatorio antes de cotizar.'
            );
        }

        return DB::transaction(function () use ($repair, $note) {
            $from = $repair->status;
            $repair->update([
                'status' => RepairStatus::Cotizado,
                'quoted_at' => now(),
            ]);
            $this->logStatusChange($repair, $from, RepairStatus::Cotizado, $note);
            return $repair->fresh();
        });
    }

    /**
     * Aprobar la cotización (cliente acepta).
     *
     * Si el cliente deja anticipo > 0:
     *   - Requiere caja abierta en la sucursal.
     *   - Registra `CashMovementType::RepairAdvancePayment` (inflow).
     *   - Actualiza `repair.advance_payment` con el monto cobrado.
     *   - Registra evento `AdvancePaid` con metadata del cash_movement_id.
     *
     * @throws RepairTransitionException
     * @throws NoHayCajaAbiertaException si advance > 0 sin caja abierta
     */
    public function aprobar(Repair $repair, float $advancePayment = 0.0, ?string $note = null): Repair
    {
        $this->assertCanTransitionTo($repair, RepairStatus::Aprobado);

        if ($advancePayment < 0) {
            throw new \InvalidArgumentException("Anticipo no puede ser negativo: {$advancePayment}");
        }
        if ($advancePayment > (float) $repair->total) {
            throw new \DomainException(
                "Anticipo ({$advancePayment}) no puede exceder el total de la cotización ({$repair->total})."
            );
        }

        return DB::transaction(function () use ($repair, $advancePayment, $note) {
            $from = $repair->status;
            $cashMovementId = null;

            if ($advancePayment > 0) {
                $establishment = $repair->establishment_id
                    ? $repair->establishment
                    : $this->establishmentResolver->resolve();

                $movement = $this->cashSessionService->recordMovementWithinTransaction(
                    $establishment->id,
                    [
                        'user_id' => Auth::id(),
                        'type' => CashMovementType::RepairAdvancePayment->value,
                        'payment_method' => PaymentMethod::Efectivo->value,
                        'amount' => $advancePayment,
                        'description' => "Anticipo de reparación {$repair->repair_number}",
                        'reference_type' => Repair::class,
                        'reference_id' => $repair->id,
                        'occurred_at' => now(),
                    ],
                );
                $cashMovementId = $movement->id;

                $repair->advance_payment = $advancePayment;
            }

            $repair->update([
                'status' => RepairStatus::Aprobado,
                'approved_at' => now(),
                'advance_payment' => $repair->advance_payment,
            ]);

            $this->logStatusChange($repair, $from, RepairStatus::Aprobado, $note);

            if ($advancePayment > 0) {
                $this->logEvent($repair, RepairLogEvent::AdvancePaid, [
                    'amount' => number_format($advancePayment, 2, '.', ''),
                    'cash_movement_id' => $cashMovementId,
                ]);
            }

            return $repair->fresh();
        });
    }

    /**
     * Rechazar la cotización (cliente no acepta).
     *
     * Si había anticipo cobrado, este service NO decide automáticamente
     * qué hacer — eso lo orquesta el caller (modal en Filament Action) que
     * llama luego a `devolverAnticipo()` o `convertirAnticipoEnCredito()`.
     *
     * Razón: la decisión "devolver vs convertir en crédito" es de UX/negocio,
     * no del service. Mantenemos la separación (CQRS-light): este método
     * SOLO cambia estado.
     *
     * El caller debe verificar `$repair->hasAdvancePayment()` y, si retorna
     * true, llamar al método correspondiente DESPUÉS o ANTES de rechazar.
     * Recomendado: rechazar primero (estado terminal, evita más operaciones
     * sobre el repair) y luego resolver el anticipo.
     */
    public function rechazar(Repair $repair, ?string $reason = null): Repair
    {
        $this->assertCanTransitionTo($repair, RepairStatus::Rechazada);

        return DB::transaction(function () use ($repair, $reason) {
            $from = $repair->status;
            $repair->update([
                'status' => RepairStatus::Rechazada,
                'rejected_at' => now(),
            ]);
            $this->logStatusChange($repair, $from, RepairStatus::Rechazada, $reason);
            return $repair->fresh();
        });
    }

    /**
     * Iniciar la reparación efectiva.
     *
     * Si la reparación no tenía técnico asignado, se asigna ahora
     * (idealmente el usuario autenticado si tiene rol técnico, o el
     * que el caller especifique).
     */
    public function iniciarReparacion(Repair $repair, ?int $technicianId = null, ?string $note = null): Repair
    {
        $this->assertCanTransitionTo($repair, RepairStatus::EnReparacion);

        return DB::transaction(function () use ($repair, $technicianId, $note) {
            $from = $repair->status;
            $newTechnicianId = $technicianId ?? $repair->technician_id ?? Auth::id();
            $previousTechnicianId = $repair->technician_id;

            $repair->update([
                'status' => RepairStatus::EnReparacion,
                'repair_started_at' => now(),
                'technician_id' => $newTechnicianId,
            ]);

            $this->logStatusChange($repair, $from, RepairStatus::EnReparacion, $note);

            // Si cambió el técnico, registrarlo aparte para auditoría.
            if ($previousTechnicianId !== $newTechnicianId) {
                $this->logEvent($repair, RepairLogEvent::TechnicianAssigned, [
                    'previous_technician_id' => $previousTechnicianId,
                    'new_technician_id' => $newTechnicianId,
                ]);
            }

            return $repair->fresh();
        });
    }

    /**
     * Marcar la reparación como completada (lista para entrega).
     *
     * Dispara la `RepairCompletedNotification` por canal database (campana
     * Filament) a TODOS los usuarios activos con rol `admin`, `super_admin`
     * o `cajero`. Ellos llaman al cliente para coordinar la entrega.
     *
     * La notificación se envía DESPUÉS del commit de la transacción para
     * evitar el caso edge "se notificó pero el cambio de estado falló".
     * Si la query de notificación falla, no afecta el estado del repair —
     * se loguea pero el estado ya quedó persistido.
     */
    public function marcarCompletada(Repair $repair, ?string $note = null): Repair
    {
        $this->assertCanTransitionTo($repair, RepairStatus::ListoEntrega);

        $fresh = DB::transaction(function () use ($repair, $note) {
            $from = $repair->status;
            $repair->update([
                'status' => RepairStatus::ListoEntrega,
                'completed_at' => now(),
            ]);
            $this->logStatusChange($repair, $from, RepairStatus::ListoEntrega, $note);
            return $repair->fresh();
        });

        // Notificar — fuera de la transacción.
        try {
            $recipients = User::query()
                ->where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'super_admin', 'cajero']))
                ->get();

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new RepairCompletedNotification($fresh));
            }
        } catch (\Throwable $e) {
            logger()->warning('Failed to send RepairCompletedNotification', [
                'repair_id' => $fresh->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $fresh;
    }

    /**
     * Anular una reparación (acción administrativa).
     *
     * Permitido desde cualquier estado activo (no terminal). Igual que
     * `rechazar`, NO toca el anticipo automáticamente — el caller decide
     * qué hacer con él vía `devolverAnticipo` / `convertirAnticipoEnCredito`.
     */
    public function anular(Repair $repair, ?string $reason = null): Repair
    {
        $this->assertCanTransitionTo($repair, RepairStatus::Anulada);

        return DB::transaction(function () use ($repair, $reason) {
            $from = $repair->status;
            $repair->update([
                'status' => RepairStatus::Anulada,
                'cancelled_at' => now(),
            ]);
            $this->logStatusChange($repair, $from, RepairStatus::Anulada, $reason);
            return $repair->fresh();
        });
    }

    /**
     * Devolver el anticipo cobrado al cliente (egreso de caja).
     *
     * Pre-condiciones:
     *   - El repair tiene `advance_payment > 0`.
     *   - El anticipo NO ha sido resuelto previamente (sin customer_credit_id
     *     y sin RepairLogEvent::AdvanceRefunded en su bitácora).
     *   - Hay caja abierta en la sucursal.
     *   - El repair está en estado terminal (Rechazada, Anulada, Abandonada).
     *
     * Efectos atómicos:
     *   - Crea `CashMovement::RepairAdvanceRefund` (outflow).
     *   - Registra evento `AdvanceRefunded` con metadata del cash_movement_id.
     *   - El campo `advance_payment` del repair NO se modifica (auditoría).
     *
     * @throws \DomainException si las pre-condiciones no se cumplen.
     */
    public function devolverAnticipo(Repair $repair, ?string $note = null): Repair
    {
        $this->assertAdvancePending($repair);

        if (! $repair->status->isTerminal()) {
            throw new \DomainException(
                'Solo se puede devolver el anticipo cuando la reparación está en estado terminal.'
            );
        }

        return DB::transaction(function () use ($repair, $note) {
            $establishment = $repair->establishment_id
                ? $repair->establishment
                : $this->establishmentResolver->resolve();

            $movement = $this->cashSessionService->recordMovementWithinTransaction(
                $establishment->id,
                [
                    'user_id' => Auth::id(),
                    'type' => CashMovementType::RepairAdvanceRefund->value,
                    'payment_method' => PaymentMethod::Efectivo->value,
                    'amount' => $repair->advance_payment,
                    'description' => "Devolución de anticipo de reparación {$repair->repair_number}",
                    'reference_type' => Repair::class,
                    'reference_id' => $repair->id,
                    'occurred_at' => now(),
                ],
            );

            $this->logEvent($repair, RepairLogEvent::AdvanceRefunded, [
                'amount' => number_format((float) $repair->advance_payment, 2, '.', ''),
                'cash_movement_id' => $movement->id,
            ], $note);

            return $repair->fresh();
        });
    }

    /**
     * Convertir el anticipo cobrado en un saldo a favor del cliente.
     *
     * Pre-condiciones:
     *   - El repair tiene `advance_payment > 0`.
     *   - El anticipo NO ha sido resuelto previamente.
     *   - El repair tiene customer_id (no walk-in sin RTN registrado).
     *   - El repair está en estado terminal.
     *
     * Efectos atómicos:
     *   - Crea registro en `customer_credits` con balance = amount.
     *   - Asocia el repair vía `customer_credit_id`.
     *   - Registra evento `AdvanceToCredit` con metadata.
     *   - El dinero NO sale de caja (queda asentado contablemente como crédito).
     *
     * @throws \DomainException si las pre-condiciones no se cumplen.
     */
    public function convertirAnticipoEnCredito(Repair $repair, ?string $description = null): Repair
    {
        $this->assertAdvancePending($repair);

        if (! $repair->status->isTerminal()) {
            throw new \DomainException(
                'Solo se puede convertir el anticipo a crédito cuando la reparación está en estado terminal.'
            );
        }

        if (! $repair->customer_id) {
            throw new \DomainException(
                'No se puede crear crédito a favor: el cliente no está registrado (walk-in). '
                . 'Crea el cliente primero o usa la opción de devolución en efectivo.'
            );
        }

        return DB::transaction(function () use ($repair, $description) {
            $credit = CustomerCredit::create([
                'customer_id' => $repair->customer_id,
                'source_type' => CustomerCreditSource::RepairAdvance->value,
                'source_repair_id' => $repair->id,
                'establishment_id' => $repair->establishment_id,
                'amount' => $repair->advance_payment,
                'balance' => $repair->advance_payment,
                'description' => $description
                    ?? "Crédito por anticipo no usado de reparación {$repair->repair_number}",
            ]);

            $repair->update(['customer_credit_id' => $credit->id]);

            $this->logEvent($repair, RepairLogEvent::AdvanceToCredit, [
                'amount' => number_format((float) $repair->advance_payment, 2, '.', ''),
                'customer_credit_id' => $credit->id,
            ], $description);

            return $repair->fresh();
        });
    }

    /**
     * Validar que el repair tenga un anticipo pendiente de resolver.
     *
     * Anticipo "pendiente" = cobrado (>0) y aún no devuelto ni convertido.
     */
    private function assertAdvancePending(Repair $repair): void
    {
        if ((float) $repair->advance_payment <= 0) {
            throw new \DomainException(
                'Esta reparación no tiene anticipo cobrado.'
            );
        }

        if ($repair->customer_credit_id !== null) {
            throw new \DomainException(
                'El anticipo ya fue convertido en crédito a favor del cliente.'
            );
        }

        $alreadyRefunded = $repair->statusLogs()
            ->where('event_type', RepairLogEvent::AdvanceRefunded->value)
            ->exists();

        if ($alreadyRefunded) {
            throw new \DomainException(
                'El anticipo ya fue devuelto previamente al cliente.'
            );
        }
    }

    // ─── Helpers privados ──────────────────────────────────────────────

    /**
     * Guardian de transiciones — lanza si la transición no es legal.
     */
    private function assertCanTransitionTo(Repair $repair, RepairStatus $next): void
    {
        if (! $repair->status->canTransitionTo($next)) {
            throw new RepairTransitionException(
                from: $repair->status,
                to: $next,
            );
        }
    }

    /**
     * Registrar un cambio de estado en `repair_status_logs`.
     */
    private function logStatusChange(
        Repair $repair,
        RepairStatus $from,
        RepairStatus $to,
        ?string $note = null,
    ): void {
        $repair->statusLogs()->create([
            'event_type' => RepairLogEvent::StatusChange,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'changed_by' => Auth::id(),
            'metadata' => null,
            'note' => $note,
        ]);
    }

    /**
     * Registrar un evento auditable distinto a cambio de estado.
     */
    private function logEvent(
        Repair $repair,
        RepairLogEvent $eventType,
        ?array $metadata = null,
        ?string $note = null,
    ): void {
        $repair->statusLogs()->create([
            'event_type' => $eventType,
            'from_status' => null,
            'to_status' => null,
            'changed_by' => Auth::id(),
            'metadata' => $metadata,
            'note' => $note,
        ]);
    }
}
