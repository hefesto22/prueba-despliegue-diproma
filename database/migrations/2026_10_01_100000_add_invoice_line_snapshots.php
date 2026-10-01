<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de factura congeladas + descripción impresa (2026-10-01).
 *
 * Deuda que corrige: la factura y la nota de crédito leían el nombre del
 * producto de la ficha ACTUAL, así que corregir la marca o el modelo de un
 * producto cambiaba la reimpresión de facturas ya emitidas. Una copia de un
 * documento fiscal tiene que salir idéntica al original.
 *
 *   - sale_items.product_name → nombre del producto al momento de vender.
 *   - sale_items.detail       → descripción del producto, solo si la ficha
 *     pedía imprimirla (products.print_description) al momento de vender.
 *   - products.print_description → casilla "Imprimir esta descripción en la
 *     factura". Apagada por defecto: nada cambia para los productos actuales.
 *
 * Backfill: las líneas existentes reciben el nombre actual del producto, que
 * es exactamente lo que imprimen hoy — desde ahora ya no cambiarán. Un solo
 * UPDATE con subconsulta (portable MySQL/MariaDB); el volumen actual de
 * sale_items no justifica chunking.
 *
 * Sin índices: ninguna de las tres columnas se usa para filtrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('print_description')->default(false)->after('description');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('product_name')->nullable()->after('description');
            $table->text('detail')->nullable()->after('product_name');
        });

        $this->backfillProductNames();
    }

    /**
     * Pública para poder probarla sin repetir el DDL (un ALTER dentro de un
     * test hace commit implícito en MySQL y rompe RefreshDatabase).
     * Incluye productos borrados (soft delete): su línea también se congela.
     */
    public function backfillProductNames(): void
    {
        DB::statement('
            UPDATE sale_items
            SET product_name = (SELECT products.name FROM products WHERE products.id = sale_items.product_id)
            WHERE product_id IS NOT NULL AND product_name IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['product_name', 'detail']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('print_description');
        });
    }
};
