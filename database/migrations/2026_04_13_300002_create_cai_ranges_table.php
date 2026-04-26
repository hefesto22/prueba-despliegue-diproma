<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cai_ranges', function (Blueprint $table) {
            $table->id();

            // ─── Datos del CAI ────────────────────────────
            $table->string('cai', 50);                         // Código CAI de SAR (37 caracteres normalmente)
            $table->date('authorization_date');                 // Fecha de autorización
            $table->date('expiration_date');                    // Fecha límite de emisión
            $table->string('document_type', 2)->default('01'); // 01 = Factura

            // ─── Establecimiento (multi-sucursal ready) ───
            // NULL = CAI central (aplica a cualquier establecimiento).
            // Con valor = CAI específico de un establecimiento (Sistema por Sucursal SAR).
            $table->foreignId('establishment_id')->nullable()
                ->constrained()->nullOnDelete();

            // ─── Rango autorizado ─────────────────────────
            $table->string('prefix', 12);                      // XXX-XXX-XX (establecimiento-punto-tipo)
            $table->bigInteger('range_start');                  // Número inicial del rango
            $table->bigInteger('range_end');                    // Número final del rango
            $table->bigInteger('current_number');               // Último número usado (empieza en range_start - 1)

            // ─── Estado ───────────────────────────────────
            $table->boolean('is_active')->default(false);       // Solo un CAI activo por tipo de documento (o por establishment)
            $table->timestamps();

            // ─── Índices ──────────────────────────────────
            // Predicado real del resolvedor: activo + tipo + establecimiento
            $table->index(['establishment_id', 'document_type', 'is_active'], 'cai_ranges_resolver_idx');
            $table->index(['is_active', 'document_type']);
            $table->index('expiration_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cai_ranges');
    }
};
