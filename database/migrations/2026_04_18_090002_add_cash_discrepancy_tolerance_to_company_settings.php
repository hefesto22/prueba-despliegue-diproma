<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tolerancia configurable para descuadres de caja.
 *
 * Cuando el cajero cierra caja, el sistema compara `expected_closing_amount`
 * (calculado a partir de movimientos en efectivo) contra `actual_closing_amount`
 * (lo que el cajero contó físicamente). La diferencia es `discrepancy`.
 *
 * Política:
 *   - |discrepancy| ≤ tolerancia → cierre permitido con observación obligatoria.
 *   - |discrepancy| > tolerancia → bloquea cierre, requiere `authorized_by_user_id`
 *     (gerente/admin) que firma la autorización.
 *
 * Por qué decimal y no integer:
 *   - Mantiene consistencia con el resto del sistema financiero.
 *
 * Default 50.00 lempiras:
 *   - Razonable para una operación pequeña: cubre redondeos típicos y errores
 *     menores de cambio. La empresa puede ajustar según su realidad operativa.
 *
 * Por qué nullable + default y no NOT NULL:
 *   - Si en el futuro se admite "sin tolerancia" (todo descuadre requiere
 *     autorización), poner el campo en null se interpreta como "tolerancia 0".
 *     Más expresivo que forzar al admin a poner 0.00 en la UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->decimal('cash_discrepancy_tolerance', 10, 2)
                ->nullable()
                ->default(50.00)
                ->after('cai_exhaustion_warning_absolute');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('cash_discrepancy_tolerance');
        });
    }
};
