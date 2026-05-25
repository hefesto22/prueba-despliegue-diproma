<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Drop `products.slug` por deuda muerta.
 *
 * Contexto: la columna `slug` fue copiada del patrón de `Category` al crear
 * el módulo de productos, pero Diproma es single-tenant sin frontend público
 * — ninguna ruta, query, vista Blade, Resource Filament ni Service consume
 * `products.slug`. Verificado con grep exhaustivo (2026-05-25).
 *
 * El problema operativo: el slug se autogenera del `name`, y el `name` se
 * autogenera de `tipo + marca + modelo + key specs`. Al registrar dos
 * unidades del mismo modelo (caso normal en Diproma — venden el mismo
 * Lenovo IdeaPad varias veces al mes) chocaba el UNIQUE y tiraba 500.
 *
 * El SKU sigue manejando bien la unicidad por unidad (correlativo
 * LAP-LEN-00014, LAP-LEN-00015). El slug nunca tuvo razón de existir.
 *
 * down(): recrea la columna como nullable para permitir rollback en caliente
 * si hubiera datos existentes (los registros viejos quedarían con slug NULL,
 * pero como nadie los consume, no rompe nada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // dropUnique primero — la constraint depende de la columna.
            // Nombre por convención Laravel: <tabla>_<columna>_unique.
            $table->dropUnique('products_slug_unique');
            $table->dropColumn('slug');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Nullable + sin unique en el rollback: permite revertir sin
            // chocar contra registros existentes que no tendrían slug.
            // Si alguna vez se necesita reactivar, una nueva migración
            // dedicada debe poblar valores y aplicar la constraint.
            $table->string('slug')->nullable()->after('name');
        });
    }
};
