<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Product;
use App\Models\SaleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesMatriz;
use Tests\TestCase;

/**
 * Backfill de 2026_10_01_100000_add_invoice_line_snapshots: las líneas de
 * venta existentes congelan el nombre que imprimen hoy (el actual), incluso
 * si el producto está borrado. Las líneas sin producto no se tocan.
 */
class InvoiceLineSnapshotsMigrationTest extends TestCase
{
    use CreatesMatriz, RefreshDatabase;

    private const MIGRATION = '2026_10_01_100000_add_invoice_line_snapshots.php';

    public function test_las_lineas_existentes_congelan_el_nombre_actual_del_producto(): void
    {
        $vigente = Product::factory()->create();
        $borrado = Product::factory()->create();

        $lineaVigente = SaleItem::factory()->create(['product_id' => $vigente->id]);
        $lineaBorrado = SaleItem::factory()->create(['product_id' => $borrado->id]);
        $lineaHonorario = SaleItem::factory()->create(['product_id' => null, 'description' => 'HONORARIOS']);

        $borrado->delete();

        // Estado previo al deploy: ninguna línea tiene nombre congelado.
        DB::table('sale_items')->update(['product_name' => null]);

        (require database_path('migrations/'.self::MIGRATION))->backfillProductNames();

        $this->assertSame($vigente->name, $lineaVigente->fresh()->product_name);
        $this->assertSame($borrado->name, $lineaBorrado->fresh()->product_name, 'El producto borrado también se congela.');
        $this->assertNull($lineaHonorario->fresh()->product_name);
        $this->assertNull($lineaVigente->fresh()->detail, 'Ninguna factura vieja gana descripción.');
    }
}
