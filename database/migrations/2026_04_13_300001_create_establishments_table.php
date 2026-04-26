<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Establecimientos del Obligado Tributario (Acuerdo 481-2017, Art. 3).
 *
 * Un RTN (empresa) puede tener N establecimientos. Cada establecimiento tiene
 * su propio código SAR (XXX) y punto de emisión (XXX).
 *
 * Compatible con las tres variantes SAR:
 *   - Centralizado: un único establecimiento + CAI sin establishment_id.
 *   - Regional:     un establecimiento por región + CAI por región.
 *   - Por Sucursal: un establecimiento por sucursal + CAI por sucursal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('establishments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_setting_id')->constrained()->cascadeOnDelete();

            // ─── Códigos SAR ──────────────────────────────
            $table->string('code', 3);              // Código de establecimiento (ej: 001)
            $table->string('emission_point', 3);    // Punto de emisión (ej: 001)

            // ─── Identificación ───────────────────────────
            $table->string('name');                 // "Matriz", "Sucursal Tegucigalpa"
            $table->enum('type', ['fijo', 'movil'])->default('fijo'); // Art. 3.1 / 3.2 SAR

            // ─── Ubicación ────────────────────────────────
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('department')->nullable();
            $table->string('municipality')->nullable();
            $table->string('phone')->nullable();

            // ─── Estado ───────────────────────────────────
            $table->boolean('is_main')->default(false);   // Solo uno es matriz
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // ─── Restricciones e índices ──────────────────
            // Un establecimiento no puede repetir el par (code, emission_point) dentro de la misma empresa.
            $table->unique(['company_setting_id', 'code', 'emission_point'], 'establishments_code_point_unq');
            $table->index(['company_setting_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishments');
    }
};
