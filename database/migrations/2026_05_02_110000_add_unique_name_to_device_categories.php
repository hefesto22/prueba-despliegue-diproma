<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega UNIQUE a `device_categories.name`.
 *
 * Razón: descubrimos en operación que `DeviceCategoryFactory` (usada en
 * tests / tinker / pruebas manuales) generaba slugs únicos pero permitía
 * duplicar el `name`, lo que dejaba "Componente" apareciendo dos veces
 * en el dropdown de "Tipo de equipo" del form de reparación.
 *
 * La factory ya fue corregida (genera nombres tipo "Componente Test 042"
 * que no chocan con los reales), pero esta constraint a nivel BD es la
 * defensa final: si alguien reintroduce un name duplicado, falla en el
 * INSERT en vez de aparecer como bug visual semanas después.
 *
 * Pre-requisito: limpiar duplicados existentes ANTES de aplicar la
 * constraint, o la migración fallará. Hacemos el cleanup en `up()` de
 * forma idempotente: por cada `name` con >1 registro, conservamos el
 * de menor id (el más antiguo, asumido como el "real" del seeder) y
 * borramos los demás.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cleanup defensivo de duplicados antes de aplicar UNIQUE.
        // Compatible con MySQL y PostgreSQL.
        $duplicates = DB::table('device_categories')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('name');

        foreach ($duplicates as $name) {
            $idsToKeep = DB::table('device_categories')
                ->where('name', $name)
                ->orderBy('id')
                ->limit(1)
                ->pluck('id');

            DB::table('device_categories')
                ->where('name', $name)
                ->whereNotIn('id', $idsToKeep)
                ->delete();
        }

        Schema::table('device_categories', function (Blueprint $table) {
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('device_categories', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });
    }
};
