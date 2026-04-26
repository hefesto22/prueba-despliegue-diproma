<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesión de caja por sucursal.
 *
 * Una sesión representa el período entre la apertura y cierre de una caja
 * física por un cajero. La regla operativa es: a lo sumo UNA sesión abierta
 * por sucursal en cualquier momento (enforced por unique parcial en `closed_at`).
 *
 * Campos clave:
 *   - opening_amount: monto físico contado al abrir caja (en lempiras).
 *   - expected_closing_amount: lo que el sistema calcula que debería haber al
 *     cerrar = opening + ingresos_efectivo − egresos_efectivo.
 *   - actual_closing_amount: lo que el cajero contó físicamente al cerrar.
 *   - discrepancy: actual - expected (positivo = sobra, negativo = falta).
 *   - authorized_by_user_id: requerido SOLO cuando |discrepancy| supera la
 *     tolerancia configurada en company_settings.cash_discrepancy_tolerance.
 *
 * Por qué `is_open` derivable como `closed_at IS NULL` y no columna boolean:
 *   - Una sola fuente de verdad (closed_at). No hay riesgo de incoherencia
 *     entre is_open=true + closed_at=2025-04-18.
 *   - Permite el unique parcial "una sola sesión abierta por sucursal".
 *
 * Por qué decimales y no integer cents:
 *   - El resto del sistema (sales, purchases, isv) usa decimal(12,2). Mantener
 *     consistencia evita conversiones manuales en cada cálculo.
 *
 * Indices:
 *   - (establishment_id, closed_at): la query "¿hay caja abierta acá?" filtra por
 *     ambos. Selectivity alta porque cada sucursal opera su propia secuencia.
 *   - (opened_by_user_id, opened_at): histórico de cajas operadas por un cajero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();

            // Sucursal donde opera esta caja. restrictOnDelete porque borrar una
            // sucursal con cajas históricas perdería trazabilidad fiscal.
            $table->foreignId('establishment_id')
                ->constrained()
                ->restrictOnDelete();

            // ─── Apertura ───────────────────────────────────────
            $table->foreignId('opened_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->decimal('opening_amount', 12, 2);

            // ─── Cierre (nullable hasta que se cierre) ──────────
            $table->foreignId('closed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            // Lo que el sistema espera (calculado a partir de los movimientos).
            $table->decimal('expected_closing_amount', 12, 2)->nullable();
            // Lo que el cajero efectivamente contó.
            $table->decimal('actual_closing_amount', 12, 2)->nullable();
            // Persistido (no recalculado) para auditoría histórica intacta aún si
            // cambia la fórmula de cálculo en el futuro.
            $table->decimal('discrepancy', 12, 2)->nullable();

            // Autorización requerida cuando |discrepancy| > tolerancia.
            $table->foreignId('authorized_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('notes')->nullable();

            // ─── Auditoría ──────────────────────────────────────
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Índices diseñados para las queries reales del sistema.
            $table->index(['establishment_id', 'closed_at'], 'cash_sessions_estab_closed_idx');
            $table->index(['opened_by_user_id', 'opened_at'], 'cash_sessions_opener_opened_idx');
            $table->index('opened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};
