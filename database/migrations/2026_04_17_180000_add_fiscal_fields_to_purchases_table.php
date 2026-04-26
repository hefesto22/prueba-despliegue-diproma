<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Añade campos fiscales SAR a `purchases` — prerequisito del Libro de Compras.
 *
 * El SAR exige que cada fila del Libro de Compras reporte el documento
 * EMITIDO POR EL PROVEEDOR (número + CAI + tipo), NO el correlativo interno
 * que Diproma asigna a la compra. Además debe discriminar la base gravada
 * vs. la exenta para calcular correctamente el ISV crédito fiscal del
 * período (Formulario ISV-353).
 *
 * Campos nuevos:
 *   - `supplier_invoice_number`: N° de factura tal como la emitió el proveedor
 *     (ej: "001-001-01-00001234"). Nullable por backfill; PurchaseService lo
 *     exige en compras nuevas.
 *   - `supplier_cai`: CAI del proveedor. Nullable porque proveedores pequeños
 *     sin régimen formal emiten recibos sin CAI (válidos para gasto pero no
 *     dan crédito fiscal — se reportan igual en el libro como referencia).
 *   - `document_type`: Tipo SAR del documento (01 factura, 03 NC proveedor,
 *     04 ND proveedor). Hoy solo se usa '01'; 03/04 quedan reservados para
 *     el módulo de NC/ND de proveedores (fuera de este scope).
 *   - `taxable_total`: Base gravada 15% del período (derivada de items con
 *     tax_type = Gravado15). Sobre ésta se calcula el ISV crédito fiscal.
 *   - `exempt_total`: Base exenta (derivada de items con tax_type = Exento).
 *
 * Índices:
 *   - `['date', 'status']`: consulta principal del libro — whereBetween(date)
 *     filtrado por estado para agrupar vigentes/anuladas.
 *   - `['supplier_id', 'date']`: filtrar libro por proveedor + período. El
 *     índice existente `['supplier_id', 'status']` no sirve para esta query.
 *
 * Backfill: las compras existentes reciben defaults coherentes —
 * document_type='01' (factura), taxable_total=subtotal (asumimos todo
 * gravado porque así operó el módulo hasta ahora), exempt_total=0.
 * supplier_invoice_number y supplier_cai quedan NULL en registros antiguos;
 * el contador los puede editar manualmente si necesita reportarlos al SAR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            // Documento del proveedor (lo que SAR exige reportar)
            $table->string('supplier_invoice_number', 30)->nullable()->after('purchase_number');
            $table->string('supplier_cai', 37)->nullable()->after('supplier_invoice_number');
            $table->string('document_type', 2)->default('01')->after('supplier_cai');

            // Separación fiscal (hoy sólo hay `subtotal` sumado)
            $table->decimal('taxable_total', 12, 2)->default(0)->after('subtotal');
            $table->decimal('exempt_total', 12, 2)->default(0)->after('taxable_total');

            // Índices para el Libro de Compras SAR
            $table->index(['date', 'status'], 'purchases_date_status_index');
            $table->index(['supplier_id', 'date'], 'purchases_supplier_date_index');
        });

        // Backfill: compras existentes reciben taxable_total = subtotal
        // (asumimos que todo lo comprado hasta hoy fue gravado — era la
        // única modalidad que el módulo soportaba). document_type ya quedó
        // en '01' por default; exempt_total en 0.
        DB::table('purchases')->update([
            'taxable_total' => DB::raw('subtotal'),
        ]);
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex('purchases_date_status_index');
            $table->dropIndex('purchases_supplier_date_index');

            $table->dropColumn([
                'supplier_invoice_number',
                'supplier_cai',
                'document_type',
                'taxable_total',
                'exempt_total',
            ]);
        });
    }
};
