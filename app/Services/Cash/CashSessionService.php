<?php

namespace App\Services\Cash;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Exceptions\Cash\CajaYaAbiertaException;
use App\Exceptions\Cash\ConciliacionPendienteException;
use App\Exceptions\Cash\DescuadreExcedeTolerancianException;
use App\Exceptions\Cash\MovimientoEnSesionCerradaException;
use App\Exceptions\Cash\NoHayCajaAbiertaException;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service de orquestación del ciclo de vida de una sesión de caja.
 *
 * Responsabilidades:
 *   - Abrir una sesión (con validación "no hay otra abierta en la sucursal").
 *   - Cerrar una sesión (calcular expected, validar tolerancia, exigir
 *     autorización si supera).
 *   - Localizar la sesión abierta de una sucursal.
 *
 * NO se ocupa de:
 *   - Crear movimientos arbitrarios → eso es responsabilidad de quien dispara
 *     el movimiento (POS al completar venta, formulario de gastos, etc.).
 *     Este service expone `recordMovement()` como helper transaccional usable
 *     desde cualquier caller, pero la decisión de QUÉ registrar es del caller.
 *   - Cálculos puros → delegados a `CashBalanceCalculator`.
 *
 * Concurrencia:
 *   - `open()` usa `lockForUpdate()` sobre la sucursal para evitar dos
 *     aperturas simultáneas (race condition al inicio del día).
 *   - `close()` usa `lockForUpdate()` sobre la sesión para evitar que se
 *     cierre dos veces en paralelo.
 */
class CashSessionService
{
    public function __construct(
        private readonly CashBalanceCalculator $calculator,
    ) {}

    /**
     * Abrir una sesión de caja en una sucursal.
     *
     * Reglas de bloqueo (en orden de evaluación):
     *   1. Si ya hay una sesión abierta en la sucursal → CajaYaAbiertaException.
     *   2. Si hay una sesión auto-cerrada pendiente de conciliar con más de
     *      `config('cash.reconciliation_grace_days')` días → ConciliacionPendienteException.
     *      El cajero/admin debe ejecutar `reconcile()` (ingresar el conteo físico
     *      real) sobre esa sesión antes de poder abrir otra. Esto evita que el
     *      kardex acumule sesiones cerradas por el sistema sin conteo real,
     *      perdiendo la pieza fiscal/operativa más importante del cuadre.
     *
     * El umbral default (7 días) cubre fines de semana largos y feriados sin
     * atascar la operación. Se puede ajustar por env CASH_RECONCILIATION_GRACE_DAYS.
     *
     * @throws CajaYaAbiertaException si ya hay una sesión abierta en la sucursal.
     * @throws ConciliacionPendienteException si hay sesión auto-cerrada > N días sin conciliar.
     */
    public function open(int $establishmentId, User $openedBy, float $openingAmount): CashSession
    {
        return DB::transaction(function () use ($establishmentId, $openedBy, $openingAmount) {
            // Lock pesimista: bloquea cualquier otra apertura concurrente para
            // esta sucursal. Si dos cajeros aprietan "abrir" al mismo tiempo,
            // uno gana y el otro recibe CajaYaAbiertaException.
            $existing = CashSession::query()
                ->where('establishment_id', $establishmentId)
                ->whereNull('closed_at')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new CajaYaAbiertaException(
                    establishmentId: $establishmentId,
                    existingSessionId: $existing->id,
                );
            }

            // Bloqueo por conciliación pendiente: si la sucursal tiene una
            // sesión auto-cerrada hace más de N días sin que un humano haya
            // ingresado el conteo físico real, no permitimos abrir otra.
            $this->guardAgainstPendingReconciliation($establishmentId);

            $session = CashSession::create([
                'establishment_id' => $establishmentId,
                'opened_by_user_id' => $openedBy->id,
                'opened_at' => now(),
                'opening_amount' => $openingAmount,
            ]);

            // Asentamiento de apertura: documento histórico del monto inicial.
            // No afecta el cálculo de expected_cash (opening_amount ya está en
            // la fórmula), pero deja trazabilidad explícita en el kardex.
            CashMovement::create([
                'cash_session_id' => $session->id,
                'user_id' => $openedBy->id,
                'type' => CashMovementType::OpeningBalance,
                'payment_method' => PaymentMethod::Efectivo,
                'amount' => $openingAmount,
                'description' => 'Apertura de caja',
                'occurred_at' => now(),
            ]);

            return $session->fresh();
        });
    }

    /**
     * Cerrar una sesión de caja.
     *
     * @param  CashSession  $session              Sesión a cerrar (debe estar abierta).
     * @param  User         $closedBy             Quien cierra.
     * @param  float        $actualClosingAmount  Monto físico contado por el cajero.
     * @param  string|null  $notes                Observación obligatoria si hay descuadre.
     * @param  User|null    $authorizedBy         Requerido si |descuadre| > tolerancia.
     *
     * @throws DescuadreExcedeTolerancianException si descuadre supera tolerancia y falta autorización.
     */
    public function close(
        CashSession $session,
        User $closedBy,
        float $actualClosingAmount,
        ?string $notes = null,
        ?User $authorizedBy = null,
    ): CashSession {
        return DB::transaction(function () use ($session, $closedBy, $actualClosingAmount, $notes, $authorizedBy) {
            // Re-fetch con lock para evitar doble cierre concurrente.
            $locked = CashSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->isClosed()) {
                throw new MovimientoEnSesionCerradaException($session->id);
            }

            $expected = $this->calculator->expectedCash($locked);
            $discrepancy = $this->calculator->discrepancy($actualClosingAmount, $expected);
            $tolerance = CompanySetting::current()->effectiveCashDiscrepancyTolerance();

            // Si el descuadre absoluto supera la tolerancia y nadie firmó, bloquear.
            if (abs($discrepancy) > $tolerance && $authorizedBy === null) {
                throw new DescuadreExcedeTolerancianException(
                    sessionId: $locked->id,
                    discrepancy: $discrepancy,
                    tolerance: $tolerance,
                );
            }

            $locked->update([
                'closed_at' => now(),
                'closed_by_user_id' => $closedBy->id,
                'expected_closing_amount' => $expected,
                'actual_closing_amount' => $actualClosingAmount,
                'discrepancy' => $discrepancy,
                'authorized_by_user_id' => $authorizedBy?->id,
                'notes' => $notes,
            ]);

            // Asentamiento de cierre — documento histórico inmutable.
            CashMovement::create([
                'cash_session_id' => $locked->id,
                'user_id' => $closedBy->id,
                'type' => CashMovementType::ClosingBalance,
                'payment_method' => PaymentMethod::Efectivo,
                'amount' => $actualClosingAmount,
                'description' => 'Cierre de caja',
                'occurred_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Cerrar una sesión automáticamente desde el sistema (job de auto-cierre).
     *
     * Diferencias clave con `close()`:
     *   - NO recibe `actual_closing_amount` (el sistema no contó plata física).
     *   - NO calcula `discrepancy` (sin conteo no hay descuadre que medir).
     *   - NO valida tolerancia (no aplica — esa validación protege al humano
     *     que está cerrando con plata en mano, acá el humano no participó).
     *   - Marca `closed_by_system_at` y `requires_reconciliation = true` para
     *     que el dashboard del admin/contador muestre la sesión pendiente y
     *     el cajero la concilie posteriormente con `reconcile()`.
     *   - SÍ guarda `expected_closing_amount` calculado desde los movimientos
     *     registrados, para que la conciliación posterior tenga la referencia
     *     contra la que comparar el conteo físico tardío.
     *   - Asentamiento de cierre con `description = "Cierre automático del sistema"`
     *     para diferenciarlo del cierre manual en auditoría.
     *
     * Concurrencia: lockForUpdate sobre la sesión, igual que `close()`. Si un
     * humano alcanzó a apretar "Cerrar mi caja" justo cuando el job corría,
     * el primero que tome el lock gana — el otro encuentra la sesión cerrada
     * y aborta limpio (MovimientoEnSesionCerradaException).
     *
     * Idempotencia: si la sesión ya está cerrada por cualquier vía (manual o
     * sistema previo), lanza `MovimientoEnSesionCerradaException`. Esto permite
     * que el job lo capture y siga con la siguiente sesión sin abortar.
     *
     * @throws MovimientoEnSesionCerradaException si la sesión ya está cerrada.
     */
    public function closeBySystem(CashSession $session): CashSession
    {
        return DB::transaction(function () use ($session) {
            $locked = CashSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->isClosed()) {
                throw new MovimientoEnSesionCerradaException($session->id);
            }

            $expected = $this->calculator->expectedCash($locked);
            $now = now();

            $locked->update([
                'closed_at'               => $now,
                'closed_by_system_at'     => $now,
                'requires_reconciliation' => true,
                'expected_closing_amount' => $expected,
                // actual_closing_amount, discrepancy, closed_by_user_id quedan NULL.
                // Se completarán en reconcile() cuando un humano cuente la plata.
            ]);

            CashMovement::create([
                'cash_session_id' => $locked->id,
                // Atribuimos al user "sistema" (no al cajero que abrió). Razón:
                // el cajero no estaba presente al cierre — atribuirle el evento
                // contamina el reporte "movimientos por usuario" y, cuando el
                // job dispatchee notificaciones por movimiento, evitaría notificar
                // a un humano por una acción que no ejecutó. El system user es
                // un actor técnico reservado, creado por SystemUserSeeder.
                'user_id'         => User::system()->id,
                'type'            => CashMovementType::ClosingBalance,
                'payment_method'  => PaymentMethod::Efectivo,
                'amount'          => $expected, // sin conteo físico, registramos el esperado
                'description'     => 'Cierre automático del sistema',
                'occurred_at'     => $now,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Conciliar una sesión auto-cerrada con el conteo físico real posterior.
     *
     * Flujo: el job auto-cerró la sesión sin contar plata. Al día siguiente
     * (o cuando el cajero/admin retoma la operación), abre la sesión pendiente,
     * cuenta el efectivo que quedó en el cajón y ejecuta este método. El
     * sistema entonces:
     *   - Recalcula `expected_closing_amount` (por si hubo movimientos
     *     legítimamente posteriores al auto-cierre — no debería haber, pero
     *     defendemos contra ese caso).
     *   - Calcula `discrepancy = actual - expected`.
     *   - Aplica la misma regla de tolerancia que `close()`: si el descuadre
     *     supera la tolerancia y nadie autorizó, bloquea con la excepción
     *     conocida — el flujo de autorización es idéntico al cierre manual.
     *   - Limpia `requires_reconciliation = false`.
     *   - Asienta el `closed_by_user_id` (quien hizo la conciliación) y `notes`.
     *
     * NO se crea otro CashMovement de cierre acá: el asentamiento ya existe
     * desde `closeBySystem()`. La conciliación solo "completa" los campos
     * que quedaron NULL en la sesión, no agrega nuevos movimientos al kardex.
     *
     * @throws MovimientoEnSesionCerradaException si la sesión no estaba cerrada
     *         (no se puede conciliar lo que no está cerrado) o ya fue conciliada.
     * @throws DescuadreExcedeTolerancianException si descuadre > tolerancia y falta autorización.
     */
    public function reconcile(
        CashSession $session,
        User $reconciledBy,
        float $actualClosingAmount,
        ?string $notes = null,
        ?User $authorizedBy = null,
    ): CashSession {
        return DB::transaction(function () use ($session, $reconciledBy, $actualClosingAmount, $notes, $authorizedBy) {
            $locked = CashSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->first();

            // Validaciones de estado: la sesión debe estar cerrada Y pendiente
            // de conciliación. Si está abierta, no aplica reconcile (el flujo
            // correcto es close()). Si ya fue conciliada, idempotencia: abortar.
            if ($locked === null || $locked->isOpen() || ! $locked->isPendingReconciliation()) {
                throw new MovimientoEnSesionCerradaException($session->id);
            }

            // Recalculamos por si hubo cambios en movimientos (no debería pasar
            // pero defendemos contra ediciones manuales en cash_movements).
            $expected = $this->calculator->expectedCash($locked);
            $discrepancy = $this->calculator->discrepancy($actualClosingAmount, $expected);
            $tolerance = CompanySetting::current()->effectiveCashDiscrepancyTolerance();

            if (abs($discrepancy) > $tolerance && $authorizedBy === null) {
                throw new DescuadreExcedeTolerancianException(
                    sessionId: $locked->id,
                    discrepancy: $discrepancy,
                    tolerance: $tolerance,
                );
            }

            $locked->update([
                'closed_by_user_id'       => $reconciledBy->id,
                'expected_closing_amount' => $expected,
                'actual_closing_amount'   => $actualClosingAmount,
                'discrepancy'             => $discrepancy,
                'authorized_by_user_id'   => $authorizedBy?->id,
                'notes'                   => $notes,
                'requires_reconciliation' => false,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Sesión actualmente abierta en una sucursal — null si no hay.
     *
     * No usar `lockForUpdate` aquí: es un getter consultivo. Los callers que
     * necesiten escribir deben re-leer dentro de transacción con lock.
     */
    public function currentOpenSession(int $establishmentId): ?CashSession
    {
        return CashSession::query()
            ->where('establishment_id', $establishmentId)
            ->whereNull('closed_at')
            ->first();
    }

    /**
     * Sesión abierta o excepción tipada — útil cuando el caller no quiere
     * manejar `null`.
     *
     * @throws NoHayCajaAbiertaException
     */
    public function currentOpenSessionOrFail(int $establishmentId): CashSession
    {
        $session = $this->currentOpenSession($establishmentId);

        if ($session === null) {
            throw new NoHayCajaAbiertaException($establishmentId);
        }

        return $session;
    }

    /**
     * Registrar un movimiento en la sesión abierta de una sucursal.
     *
     * Helper transaccional. Útil para callers que solo quieren "registrar
     * un gasto en la caja activa" sin manejar la búsqueda + lock manualmente
     * y sin transacción preexistente (uso standalone desde Filament actions,
     * controllers, etc.).
     *
     * Si el caller YA está dentro de una transacción (ej. SaleService procesando
     * una venta completa), usar `recordMovementWithinTransaction()` para evitar
     * transacciones anidadas con savepoints (que pueden romper atomicidad si
     * algún código intermedio catchea la excepción del savepoint).
     *
     * @param  array<string, mixed>  $attributes  Atributos del CashMovement (sin cash_session_id).
     *
     * @throws NoHayCajaAbiertaException
     * @throws MovimientoEnSesionCerradaException
     */
    public function recordMovement(int $establishmentId, array $attributes): CashMovement
    {
        return DB::transaction(
            fn () => $this->persistMovementInOpenSession($establishmentId, $attributes)
        );
    }

    /**
     * Versión "bare" de `recordMovement()` — ejecuta el lock + create SIN
     * envolver en una transacción propia.
     *
     * E.2.A2 — Expuesto para que `SaleService::processSale` y `SaleService::cancel`
     * (que ya abren `DB::transaction(...)` al tope) registren el movimiento de
     * caja bajo la MISMA transacción del caller, sin crear un savepoint anidado.
     *
     * Contrato:
     *   - El caller DEBE estar dentro de una transacción activa. Si no lo está,
     *     el lockForUpdate no tiene efecto semántico y cualquier fallo posterior
     *     no hará rollback del INSERT — corromperás el kardex de caja. La
     *     responsabilidad es del caller, no hay verificación aquí (chequear
     *     `DB::transactionLevel() > 0` sería costoso en el hot path y frágil
     *     contra extensiones/drivers).
     *
     * Invariantes iguales a `recordMovement()`: misma sesión lock, mismas
     * excepciones, misma estructura de atributos.
     *
     * @param  array<string, mixed>  $attributes  Atributos del CashMovement (sin cash_session_id).
     *
     * @throws NoHayCajaAbiertaException
     * @throws MovimientoEnSesionCerradaException
     */
    public function recordMovementWithinTransaction(int $establishmentId, array $attributes): CashMovement
    {
        return $this->persistMovementInOpenSession($establishmentId, $attributes);
    }

    /**
     * Implementación compartida: lock de la sesión abierta + create del movimiento.
     *
     * Extraído como private para que `recordMovement()` y
     * `recordMovementWithinTransaction()` compartan la misma lógica sin
     * duplicarla. La única diferencia entre los dos públicos es el wrapper
     * transaccional — la semántica de "qué se registra" vive acá.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws NoHayCajaAbiertaException
     * @throws MovimientoEnSesionCerradaException
     */
    private function persistMovementInOpenSession(int $establishmentId, array $attributes): CashMovement
    {
        $session = CashSession::query()
            ->where('establishment_id', $establishmentId)
            ->whereNull('closed_at')
            ->lockForUpdate()
            ->first();

        if ($session === null) {
            throw new NoHayCajaAbiertaException($establishmentId);
        }

        // Defense in depth: si entre el lock y la inserción la sesión
        // se cerrara (no debería poder, pero...), fallar ruidoso.
        if ($session->isClosed()) {
            throw new MovimientoEnSesionCerradaException($session->id);
        }

        return CashMovement::create([
            'cash_session_id' => $session->id,
            'occurred_at' => $attributes['occurred_at'] ?? now(),
            ...$attributes,
        ]);
    }

    /**
     * Lanzar `ConciliacionPendienteException` si la sucursal tiene una sesión
     * auto-cerrada hace más de N días sin conciliar.
     *
     * Implementación: buscamos la sesión pendiente más antigua. Si su
     * `closed_by_system_at` está antes de "hoy menos N días", bloqueamos.
     * Si el campo está NULL (no debería estar si requires_reconciliation=true,
     * pero defendemos), la consideramos "fresca" y no bloqueamos.
     *
     * Performance: O(1) por el índice compuesto
     * `cash_sessions_estab_reconc_idx (establishment_id, requires_reconciliation)`.
     * Selecciona solo las columnas necesarias para evitar hidratar el modelo
     * completo en el hot path de apertura de caja.
     *
     * @throws ConciliacionPendienteException
     */
    private function guardAgainstPendingReconciliation(int $establishmentId): void
    {
        /** @var \stdClass|null $pending */
        $pending = CashSession::query()
            ->select(['id', 'closed_by_system_at'])
            ->where('establishment_id', $establishmentId)
            ->where('requires_reconciliation', true)
            ->whereNotNull('closed_by_system_at')
            ->orderBy('closed_by_system_at') // la más antigua primero
            ->first();

        if ($pending === null) {
            return;
        }

        $thresholdDays = (int) config('cash.reconciliation_grace_days', 7);
        $closedAt = Carbon::parse($pending->closed_by_system_at);
        $daysSince = (int) $closedAt->diffInDays(now());

        if ($daysSince > $thresholdDays) {
            throw new ConciliacionPendienteException(
                establishmentId: $establishmentId,
                pendingSessionId: $pending->id,
                daysSinceAutoClose: $daysSince,
                thresholdDays: $thresholdDays,
            );
        }
    }
}
