<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F6a.5 — Sucursal activa del usuario.
 *
 * Agrega users.default_establishment_id para que cada usuario tenga su sucursal
 * de trabajo persistida en DB. Los Services (POS, ajustes manuales, etc.)
 * resuelven la sucursal activa vía EstablishmentResolver, el cual prioriza
 * esta columna sobre la matriz como fallback.
 *
 * Decisiones:
 * - Nullable: un user recién creado todavía no tiene sucursal asignada
 *   (el admin la asigna al editarlo). Sin nullable romperíamos el onboarding.
 * - nullOnDelete: si la sucursal se elimina, el usuario queda sin default
 *   (no bloqueamos el delete de la sucursal por esto — el admin reasigna).
 * - Sin índice: esta columna nunca se usa como filtro en queries; se lee
 *   únicamente vía PK lookup del user autenticado ($user->default_establishment_id).
 *   Agregar un índice sería overhead sin beneficio real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('default_establishment_id')
                ->nullable()
                ->after('is_active')
                ->constrained('establishments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_establishment_id');
        });
    }
};
