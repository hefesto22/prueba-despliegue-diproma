<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix E.2.A4: realinear la semántica de `invoices.taxable_total`.
 *
 * ## Contexto del bug
 *
 * Hasta el refactor E.2.A4, `InvoiceService::calculateFiscalBreakdown`
 * persistía `taxable_total = Sale.subtotal`. Sin embargo `Sale.subtotal`
 * — por convención heredada de `SaleTaxCalculator` — suma TANTO la base
 * gravada COMO la base exenta post-descuento. Eso hacía que el campo
 * `invoices.taxable_total` contuviera gravado + exento en lugar de
 * solo gravado, mientras que los consumidores (`SalesBookService`,
 * `SalesBookEntry::fromInvoice`, `InvoicePrintService`) lo leían como
 * solo gravado.
 *
 * Efecto fiscal: el Libro de Ventas SAR reportaba la columna "Ventas
 * Gravadas" sumando también lo exento para cualquier factura con
 * producto exento. El ISV declarado y el total NO se vieron afectados
 * (esos campos siempre fueron correctos), solo la segregación
 * gravado/exento en el archivo entregado a DEI.
 *
 * ## Alcance en producción (auditoría 2026-04-19)
 *
 * Query: `invoices WHERE exempt_total > 0 AND is_void = false`
 * Resultado: 4 filas afectadas (ids 2, 3, 9, 10). Todas dentro del
 * período fiscal actual (abril 2026), con el Libro SAR correspondiente
 * aún sin entregar → fix limpio sin necesidad de rectificativa.
 *
 * ## Lógica del fix
 *
 * Invariante post-fix: `taxable_total + exempt_total == subtotal`
 * (± 0.01 por redondeo).
 *
 *   taxable_total := ROUND(subtotal - exempt_total, 2)
 *
 * Solo aplica a facturas con `exempt_total > 0`. Las all-gravado ya
 * tenían `taxable_total == subtotal == base gravada` y no requieren
 * cambio.
 *
 * ## Bypass del Observer LocksFiscalFieldsAfterEmission
 *
 * Usamos Query Builder (`DB::table`) en lugar de Eloquent porque las
 * facturas afectadas ya están emitidas (`emitted_at != null`) y el
 * Observer bloquearía cualquier UPDATE sobre `taxable_total`. Este
 * bypass es legítimo: no estamos modificando el documento legal —
 * estamos corrigiendo un campo interno de clasificación que nunca
 * debería haber tenido el valor que tenía. El `integrity_hash`
 * (SHA-256 sobre id + invoice_number + cai + rtns + total + fecha)
 * NO incluye `taxable_total`, por lo tanto los QR ya impresos siguen
 * verificando correctamente después de esta migración.
 *
 * ## Auditoría del cambio
 *
 * La migración imprime en consola los ids afectados y los valores
 * antes/después para trazabilidad en los logs de deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $afectadas = DB::table('invoices')
            ->where('exempt_total', '>', 0)
            ->get(['id', 'invoice_number', 'subtotal', 'exempt_total', 'taxable_total']);

        if ($afectadas->isEmpty()) {
            $this->info('No hay facturas con exempt_total > 0 — nada que migrar.');

            return;
        }

        DB::transaction(function () use ($afectadas) {
            foreach ($afectadas as $row) {
                $nuevo = round((float) $row->subtotal - (float) $row->exempt_total, 2);

                DB::table('invoices')
                    ->where('id', $row->id)
                    ->update(['taxable_total' => $nuevo]);

                $this->info(sprintf(
                    '[FIX E.2.A4] Invoice #%d (%s): taxable_total %s → %s (exempt=%s, subtotal=%s)',
                    $row->id,
                    $row->invoice_number,
                    number_format((float) $row->taxable_total, 2),
                    number_format($nuevo, 2),
                    number_format((float) $row->exempt_total, 2),
                    number_format((float) $row->subtotal, 2),
                ));
            }
        });

        $this->info(sprintf('[FIX E.2.A4] Migración aplicada: %d facturas realineadas.', $afectadas->count()));
    }

    public function down(): void
    {
        // Rollback: restaurar el comportamiento buggy (taxable_total = subtotal)
        // solo para las filas que esta migración tocó. Mantiene simetría con up().
        // NO es reversible ideal (pierde la distinción que antes no existía), pero
        // es el único rollback posible dado que la data pre-migración NO distinguía
        // entre gravado y gravado+exento.
        DB::table('invoices')
            ->where('exempt_total', '>', 0)
            ->update([
                'taxable_total' => DB::raw('subtotal'),
            ]);
    }

    /**
     * Helper de logging al CLI de artisan migrate (stdout).
     * Si corre fuera de CLI (ej. test con RefreshDatabase) degrada a silent.
     */
    private function info(string $message): void
    {
        if (app()->runningInConsole() && ! app()->environment('testing')) {
            echo "  {$message}\n";
        }
    }
};
