<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Limpia las tablas operativas para regenerar el demo desde cero.
 *
 * Diseñado para correr ANTES del orquestador de demo cuando se necesita un
 * reset limpio sin perder el catálogo de productos ni la configuración del
 * sistema. Alternativa más quirúrgica que `migrate:fresh` para ambientes
 * donde queremos preservar productos / categorías / spec_options seedeados.
 *
 * ────────────────────────────────────────────────────────────────────────
 * QUÉ SE LIMPIA (datos transaccionales y operativos)
 * ────────────────────────────────────────────────────────────────────────
 *   - Movimientos: inventory_movements, cash_movements
 *   - Caja: cash_sessions
 *   - Gastos: expenses
 *   - Fiscal: isv_retention_received, isv_monthly_declarations, fiscal_periods
 *   - Notas de crédito: credit_note_items, credit_notes
 *   - Facturas: invoice_items, invoices
 *   - Ventas: sale_items, sales
 *   - Compras: purchase_items, purchases
 *   - Catálogo operativo: customers (todos), suppliers (excepto el genérico
 *     "Varios / Sin identificar" que vive en migración, no en seeder)
 *   - CAI ranges: se vacían — el seeder los regenerará
 *   - Stock de productos: reseteado a 0 (las compras del demo van a llenarlo)
 *   - Costo histórico de productos: NO se toca (es parte del catálogo)
 *
 * ────────────────────────────────────────────────────────────────────────
 * QUÉ NO SE TOCA
 * ────────────────────────────────────────────────────────────────────────
 *   - products, product_specifications (catálogo)
 *   - categories
 *   - spec_options, spec_values
 *   - users, roles, permissions, model_has_*
 *   - company_settings
 *   - establishments
 *   - activity_log (histórico de auditoría — se preserva)
 *
 * ────────────────────────────────────────────────────────────────────────
 * SEGURIDAD: NO USAR EN PRODUCCIÓN
 * ────────────────────────────────────────────────────────────────────────
 *   Esta clase trunca tablas con datos de transacciones. En producción sería
 *   catastrófica. La protección es operativa (no técnica): solo se invoca
 *   desde el orquestador del demo, que documenta su uso. Si alguien llama
 *   este seeder en prod, el daño es responsabilidad de quien lo invocó.
 *
 * Idempotente: truncate sobre tabla vacía es no-op.
 */
class TruncateOperationalDataSeeder extends Seeder
{
    /**
     * Tablas a truncar en orden topológico (hijas primero).
     *
     * El orden importa para algunos motores aunque desactivemos FKs —
     * mantenerlo correcto evita warnings y deja el código auto-documentado
     * sobre las dependencias del esquema.
     */
    private const TABLES_TO_TRUNCATE = [
        // Movimientos y referencias polimórficas
        'inventory_movements',
        'cash_movements',

        // Caja
        'cash_sessions',

        // Gastos
        'expenses',

        // Fiscal (declaraciones y períodos)
        'isv_monthly_declarations',
        'isv_retention_received',
        'fiscal_periods',

        // Notas de crédito (referencian invoices)
        'credit_note_items',
        'credit_notes',

        // Facturas (referencian sales)
        'invoice_items',
        'invoices',

        // Ventas
        'sale_items',
        'sales',

        // Compras
        'purchase_items',
        'purchases',

        // CAIs (se regeneran en CaiRangeDemoSeeder)
        'cai_ranges',

        // Customers (sin genéricos en este modelo)
        'customers',
    ];

    public function run(): void
    {
        $this->command?->info('  Truncando tablas operativas (preservando catálogo y configuración)…');

        Schema::disableForeignKeyConstraints();

        try {
            foreach (self::TABLES_TO_TRUNCATE as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                }
            }

            // Suppliers: borrar solo los operativos. El genérico
            // "Varios / Sin identificar" (is_generic=true) lo provee la
            // migración 2026_04_19_add_recibo_interno_support_to_suppliers
            // y NO debe re-crearse manualmente — el flujo de Recibo Interno
            // lo busca por nombre y truena si no existe.
            DB::table('suppliers')->where('is_generic', false)->delete();

            // Reset de stock de productos a 0. El histórico de compras del
            // demo va a re-llenarlo. NO tocamos cost_price porque es parte
            // del catálogo (cómo se cargó el producto inicialmente).
            DB::table('products')->update(['stock' => 0]);
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $supplierCount = Supplier::count();
        $productCount = Product::count();

        $this->command?->info(sprintf(
            '  → Tablas truncadas. Preservado: %d productos (stock reseteado a 0), %d proveedor(es) genérico(s).',
            $productCount,
            $supplierCount,
        ));
    }
}
