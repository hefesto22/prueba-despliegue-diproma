<?php

namespace App\Services\FiscalBooks;

use App\Models\CreditNote;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Servicio del Libro de Ventas SAR.
 *
 * Recolecta facturas (tipo 01) y notas de crédito (tipo 03) del período,
 * las normaliza a SalesBookEntry y calcula el SalesBookSummary del período.
 *
 * Responsabilidad única: construir el DTO SalesBook. Quién lo imprima (Excel,
 * PDF, CSV, API) es problema de otra clase — esto sigue ISP y OCP.
 *
 * Filtro por establishment_id es opcional: por defecto el libro es
 * company-wide (así lo declara el contador al SAR). Si se pasa un id, el
 * libro se restringe a esa sucursal (para conciliaciones internas).
 *
 * Uso del filtro de fecha: invoice_date / credit_note_date (la fecha de
 * emisión fiscal que aparece impresa), NO emitted_at (timestamp interno).
 * El SAR declara por fecha de documento, no por timestamp de sistema.
 */
class SalesBookService
{
    /**
     * Construir el Libro de Ventas de un período mensual.
     *
     * @param  int       $year              Año del período (ej: 2026)
     * @param  int       $month             Mes 1-12
     * @param  int|null  $establishmentId   NULL = todas las sucursales (default)
     *
     * @throws InvalidArgumentException  Si year/month están fuera de rango válido
     */
    public function build(int $year, int $month, ?int $establishmentId = null): SalesBook
    {
        $this->assertValidPeriod($year, $month);

        [$from, $to] = $this->periodRange($year, $month);

        $invoices = $this->fetchInvoices($from, $to, $establishmentId);
        $notes    = $this->fetchCreditNotes($from, $to, $establishmentId);

        $entries = $this->buildEntries($invoices, $notes);
        $summary = $this->buildSummary($year, $month, $invoices, $notes);

        return new SalesBook($entries, $summary);
    }

    /**
     * Rango [primer día, último día] del período como strings 'Y-m-d'.
     *
     * Se usa con whereBetween para que MySQL pueda aprovechar los índices
     * compuestos ['invoice_date', 'is_void'] y ['establishment_id', 'invoice_date'].
     * whereYear/whereMonth aplican funciones sobre la columna y descartan el índice.
     *
     * @return array{0: string, 1: string}
     */
    private function periodRange(int $year, int $month): array
    {
        $from = CarbonImmutable::create($year, $month, 1)->startOfMonth();
        $to   = $from->endOfMonth();

        return [$from->toDateString(), $to->toDateString()];
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function fetchInvoices(string $from, string $to, ?int $establishmentId): Collection
    {
        $query = Invoice::query()
            ->whereBetween('invoice_date', [$from, $to]);

        if ($establishmentId !== null) {
            $query->where('establishment_id', $establishmentId);
        }

        return $query
            ->orderBy('invoice_date')
            ->orderBy('invoice_number')
            ->get();
    }

    /**
     * @return Collection<int, CreditNote>
     */
    private function fetchCreditNotes(string $from, string $to, ?int $establishmentId): Collection
    {
        $query = CreditNote::query()
            ->whereBetween('credit_note_date', [$from, $to]);

        if ($establishmentId !== null) {
            $query->where('establishment_id', $establishmentId);
        }

        return $query
            ->orderBy('credit_note_date')
            ->orderBy('credit_note_number')
            ->get();
    }

    /**
     * Normaliza y ordena todas las entradas del período.
     *
     * Orden: fecha ascendente, luego tipo documento (01 antes que 03),
     * luego número. Mantiene el criterio del detalle del Libro de Ventas
     * que espera el SAR: cronológico con facturas y notas intercaladas.
     *
     * @param  Collection<int, Invoice>     $invoices
     * @param  Collection<int, CreditNote>  $notes
     * @return Collection<int, SalesBookEntry>
     */
    private function buildEntries(Collection $invoices, Collection $notes): Collection
    {
        $invoiceEntries = $invoices->map(fn (Invoice $invoice) => SalesBookEntry::fromInvoice($invoice));
        $noteEntries    = $notes->map(fn (CreditNote $note) => SalesBookEntry::fromCreditNote($note));

        return $invoiceEntries
            ->concat($noteEntries)
            ->sortBy([
                fn (SalesBookEntry $a, SalesBookEntry $b) => $a->fecha <=> $b->fecha,
                fn (SalesBookEntry $a, SalesBookEntry $b) => $a->tipoDocumento <=> $b->tipoDocumento,
                fn (SalesBookEntry $a, SalesBookEntry $b) => $a->numero <=> $b->numero,
            ])
            ->values();
    }

    /**
     * Calcular el resumen del período.
     *
     * Los totales se calculan SOLO sobre documentos vigentes (no anulados).
     * Las anuladas se cuentan por separado para la trazabilidad pero NO
     * suman en los montos — esto es lo que el contador declarará al SAR.
     *
     * @param  Collection<int, Invoice>     $invoices
     * @param  Collection<int, CreditNote>  $notes
     */
    private function buildSummary(int $year, int $month, Collection $invoices, Collection $notes): SalesBookSummary
    {
        $facturasVigentes = $invoices->where('is_void', false);
        $facturasAnuladas = $invoices->where('is_void', true);
        $notasVigentes    = $notes->where('is_void', false);
        $notasAnuladas    = $notes->where('is_void', true);

        return new SalesBookSummary(
            periodYear:  $year,
            periodMonth: $month,

            facturasEmitidasCount: $invoices->count(),
            facturasVigentesCount: $facturasVigentes->count(),
            facturasAnuladasCount: $facturasAnuladas->count(),
            facturasExento:  round((float) $facturasVigentes->sum('exempt_total'), 2),
            facturasGravado: round((float) $facturasVigentes->sum('taxable_total'), 2),
            facturasIsv:     round((float) $facturasVigentes->sum('isv'), 2),
            facturasTotal:   round((float) $facturasVigentes->sum('total'), 2),

            notasCreditoEmitidasCount: $notes->count(),
            notasCreditoVigentesCount: $notasVigentes->count(),
            notasCreditoAnuladasCount: $notasAnuladas->count(),
            notasCreditoExento:  round((float) $notasVigentes->sum('exempt_total'), 2),
            notasCreditoGravado: round((float) $notasVigentes->sum('taxable_total'), 2),
            notasCreditoIsv:     round((float) $notasVigentes->sum('isv'), 2),
            notasCreditoTotal:   round((float) $notasVigentes->sum('total'), 2),
        );
    }

    private function assertValidPeriod(int $year, int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("Mes fuera de rango (1-12): {$month}");
        }

        if ($year < 2000 || $year > 2100) {
            throw new InvalidArgumentException("Año fuera de rango (2000-2100): {$year}");
        }
    }
}
