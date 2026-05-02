<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fotos del equipo asociadas a una Reparación.
 *
 * Caso de uso: identificar rápidamente el equipo en el taller y servir como
 * evidencia para reclamos del cliente (ej: "el equipo estaba rayado al
 * recibirlo, no lo rayamos nosotros").
 *
 * Política de borrado (CleanupRepairPhotosJob, F-R6):
 *   - Borrado físico (Storage::disk delete) 7 días después de `delivered_at`.
 *   - El registro en BD se borra también para mantener consistencia.
 *   - Las fotos de reparaciones en estados terminales NO entregadas
 *     (Rechazada, Anulada, Abandonada) también se limpian tras 7 días
 *     desde la transición al estado terminal.
 *
 * Límite recomendado: 3 fotos por reparación (validado en Filament form,
 * no constraint de BD para permitir flexibilidad operativa puntual).
 *
 * Path convention: storage/app/public/repairs/{repair_id}/{filename}
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_photos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('repair_id')
                ->constrained()
                ->cascadeOnDelete();

            // Path relativo al disk 'public'.
            $table->string('photo_path', 500);

            // Propósito (RepairPhotoPurpose enum: recepcion/diagnostico/durante/finalizada).
            $table->string('purpose', 20);

            $table->string('caption', 200)->nullable();

            // Tamaño en bytes (para reportes de uso de storage).
            $table->unsignedInteger('file_size')->nullable();

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Índice para galería ordenada por reparación + propósito.
            $table->index(['repair_id', 'purpose'], 'repair_photos_repair_purpose_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_photos');
    }
};
