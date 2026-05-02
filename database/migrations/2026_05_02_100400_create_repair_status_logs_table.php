<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora WORM (write-once-read-many) de eventos de una Reparación.
 *
 * Registra TODA acción auditable: cambios de estado, edición de líneas,
 * cobro/devolución de anticipo, fotos agregadas/eliminadas, asignación
 * de técnico, actualización de diagnóstico.
 *
 * Diseño:
 *   - NUNCA se actualizan registros existentes; cada evento es una fila nueva.
 *   - Sin `updated_at` (solo `created_at`) para reforzar la inmutabilidad.
 *   - `metadata` (JSON) lleva contexto específico por tipo de evento.
 *
 * Por qué denormalizar status timestamps en `repairs` además de aquí: las
 * queries de reportes ("tiempo promedio Cotizado→Aprobado") no quieren
 * hacer joins contra esta tabla por cada repair. La tabla principal tiene
 * los timestamps clave; este log tiene el detalle completo y la metadata.
 *
 * Por qué `event_type` string (no FK a otra tabla): es un enum del dominio
 * en código (RepairLogEvent), no una entidad gestionable por usuarios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_status_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('repair_id')
                ->constrained()
                ->cascadeOnDelete();

            // RepairLogEvent enum value
            $table->string('event_type', 30);

            // Para event_type = status_change. Null en otros eventos.
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();

            // Quién disparó el evento. Nullable para eventos automáticos
            // (job de abandono automático, cleanup de fotos).
            $table->foreignId('changed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Contexto estructurado por tipo (ver docblock de RepairLogEvent).
            $table->json('metadata')->nullable();

            // Nota libre opcional (ej: razón del rechazo, comentario del técnico).
            $table->text('note')->nullable();

            // WORM: solo created_at, NO updated_at.
            $table->timestamp('created_at')->useCurrent();

            // ─── Índices ─────────────────────────────────────────────────
            // Listado del log de un repair en orden cronológico.
            $table->index(['repair_id', 'created_at'], 'repair_logs_repair_time_idx');
            // Búsqueda por tipo de evento + repair (ej: "todos los anticipos cobrados").
            $table->index(['repair_id', 'event_type'], 'repair_logs_repair_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_status_logs');
    }
};
