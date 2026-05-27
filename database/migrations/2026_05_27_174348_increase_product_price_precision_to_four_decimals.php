<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aumentar precisión interna de products.sale_price y products.cost_price
 * de DECIMAL(12,2) a DECIMAL(12,4).
 *
 * ─── Contexto ──────────────────────────────────────────────────────────────
 *
 * Reporte del cliente: a veces al guardar un producto con precio entero
 * (ej. L 380.00) el sistema lo persiste como L 379.99.
 *
 * Causa raíz: el form recibe el precio público CON ISV. Para mantener la
 * convención NETO en BD, CreateProduct::convertPricesToBase aplica back-out
 * (precio / 1.15). Con sale_price almacenado en DECIMAL(12,2):
 *
 *   380.00 / 1.15 = 330.4347826...  → round(2) = 330.43
 *   330.43 × 1.15 = 379.9945        → round(2) = 379.99  ❌
 *
 * Matemáticamente NO existe ningún valor de sale_price a 2 decimales cuyo
 * round-trip × 1.15 produzca exactamente 380.00. Es una limitación inherente
 * de precisión, no un bug de redondeo.
 *
 * Solución: subir la precisión interna a 4 decimales. El back-out preserva
 * la información necesaria para reconstruir el precio público:
 *
 *   380.00 / 1.15 = 330.4347826...  → round(4) = 330.4348
 *   330.4348 × 1.15 = 380.00002     → round(2) = 380.00  ✓
 *
 * ─── Por qué también cost_price ────────────────────────────────────────────
 *
 * El CPP móvil de compras (PurchaseService::updateProductCostAndStock)
 * calcula cost_price ponderando stock × cost_price + qty × netUnitCost.
 * Con 2 decimales, sucesivas compras acumulaban centavos perdidos.
 * Subir cost_price a 4 decimales preserva la precisión del kardex de costos
 * a lo largo del tiempo y evita drift de centavos en reportes de ganancia.
 *
 * ─── Lo que NO cambia ──────────────────────────────────────────────────────
 *
 * - El output al usuario (factura, POS, lista, Excel, PDFs) sigue siendo
 *   a 2 decimales. Los accessors priceWithIsv, getSalePriceWithIsvAttribute,
 *   getProfitAmountAttribute, calculateSaleIsv ya redondean a 2 al final.
 * - Los snapshots de kardex (inventory_movements.unit_cost, sale_items.unit_price)
 *   siguen en DECIMAL(12,2) — son historial inmutable, no fuente de cálculo.
 * - Las tablas de facturación (sales, purchases, sale_items, etc.) siguen
 *   en DECIMAL(12,2) — el SAR recibe centavos, no diezmilésimos.
 *
 * ─── Convención (FUENTE ÚNICA DE VERDAD) ───────────────────────────────────
 *
 * Precisión interna: 4 decimales para fuentes de verdad editables (products).
 * Precisión de output: 2 decimales — redondear SOLO en el punto de mostrar.
 * NUNCA aplicar round(_, 2) en cálculos intermedios sobre estas columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL: ALTER COLUMN preserva los valores existentes — un 380.43
        // pasa a guardarse como 380.4300 sin pérdida. La migración es segura
        // contra datos productivos.
        DB::statement('ALTER TABLE products MODIFY sale_price DECIMAL(12,4) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE products MODIFY cost_price DECIMAL(12,4) NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        // Revertir a DECIMAL(12,2). ATENCIÓN: si en producción ya hay valores
        // con 4 decimales significativos (ej. 330.4348), el rollback los
        // truncará a 2 (330.43) y se vuelve a sufrir el bug del round-trip.
        // Solo correr down() si los datos no han sido tocados después del up().
        DB::statement('ALTER TABLE products MODIFY sale_price DECIMAL(12,2) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE products MODIFY cost_price DECIMAL(12,2) NOT NULL DEFAULT 0');
    }
};
