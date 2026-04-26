<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-cierre de sesiones de caja a las 21:00.
 *
 * El cliente reportó que los cajeros olvidan cerrar la caja al final del
 * día. El job AutoCloseCashSessionsJob corre todos los días a las 21:00
 * y cierra las sesiones que sigan abiertas. Como el sistema no puede
 * contar la plata del cajón físicamente, ese cierre queda en estado
 * "pendiente de conciliación" y se completa con el conteo real cuando
 * un humano lo revise (al día siguiente o cuando pueda).
 *
 * Campos agregados:
 *
 *   - closed_by_system_at:
 *       Marca explícita de que el cierre fue automático (no humano).
 *       Distinto de closed_at — closed_at se llena igual cuando se
 *       cierra por sistema, pero closed_by_system_at IS NOT NULL es la
 *       señal inequívoca de "esta sesión la cerró el job".
 *
 *   - requires_reconciliation:
 *       True cuando el cierre fue automático y aún no se ingresó el
 *       conteo físico. Permite al admin filtrar "sesiones pendientes
 *       de conciliar" sin recalcular en el frontend.
 *       Pasa a false cuando ReconcileCashSessionAction inyecta el
 *       actual_closing_amount real.
 *
 * Índice (establishment_id, requires_reconciliation):
 *   La query de bloqueo en CashSessionService::open() pregunta "¿esta
 *   sucursal tiene alguna sesión auto-cerrada pendiente de conciliar
 *   con más de 7 días?". Filter por sucursal primero (alta selectividad
 *   en multi-sucursal) + flag booleano. Acelera ese check en cada
 *   apertura sin escanear toda la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->timestamp('closed_by_system_at')
                ->nullable()
                ->after('closed_at');

            $table->boolean('requires_reconciliation')
                ->default(false)
                ->after('closed_by_system_at');

            $table->index(
                ['establishment_id', 'requires_reconciliation'],
                'cash_sessions_estab_reconc_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropIndex('cash_sessions_estab_reconc_idx');
            $table->dropColumn(['closed_by_system_at', 'requires_reconciliation']);
        });
    }
};
