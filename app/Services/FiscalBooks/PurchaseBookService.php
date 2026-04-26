<?php

namespace App\Services\FiscalBooks;

use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Models\CompanySetting;
use App\Models\Purchase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Servicio del Libro de Compras SAR.
 *
 * Recolecta compras confirmadas y anuladas del período (los borradores NO
 * pertenecen al libro — fiscalmente no existen), las normaliza a
 * PurchaseBookEntry y calcula el PurchaseBookSummary del período.
 *
 * Responsabilidad única: construir el DTO PurchaseBook. Quién lo imprima
 * (Excel, PDF, CSV, API) es problema de otra clase — esto sigue SRP/ISP.
 *
 * Diferencias relevantes con SalesBookService:
 *
 *   - Un solo modelo fuente (Purchase con document_type discriminante) en
 *     lugar de dos (Invoice + CreditNote). Esto reduce la query a una sola,
 *     pero exige clasificar por `document_type` al construir el summary.
 *
 *   - Los borradores (PurchaseStatus::Borrador) se excluyen del libro —
 *     el SAR solo reconoce documentos efectivamente asentados en contabilidad.
 *
 * Filtro por establishment_id es opcional: por defecto el libro es
 * company-wide (así lo declara el contador al SAR). Si se pasa un id, el
 * libro se restringe a esa sucursal (para conciliaciones internas).
 *
 * Uso del filtro de fecha: `purchases.date` (fecha de emisión del documento
 * del proveedor), NO `created_at`. El SAR declara por fecha del documento,
 * no por timestamp de captura.
 */
class PurchaseBookService
{
    /**
     * Construir el Libro de Compras de un período mensual.
     *
     * @param  int       $year              Año del período (ej: 2026)
     * @param  int       $month             Mes 1-12
     * @param  int|null  $establishmentId   NULL = todas las sucursales (default)
     *
     * @throws InvalidArgumentException  Si year/month están fuera de rango válido
     */
    public function build(int $year, int $month, ?int $establishmentId = null): PurchaseBook
    {
        $this->assertValidPeriod($year, $month);

        [$from, $to] = $this->periodRange($year, $month);

        $purchases = $this->fetchPurchases($from, $to, $establishmentId);
        $rtnReceptor = $this->resolveRtnReceptor();

        $entries = $this->buildEntries($purchases, $rtnReceptor);
        $summary = $this->buildSummary($year, $month, $purchases);

        return new PurchaseBook($entries, $summary);
    }

    /**
     * Rango [primer día, último día] del período como strings 'Y-m-d'.
     *
     * Se usa con whereBetween para que el motor aproveche el índice compuesto
     * ['date', 'status'] agregado en la migración fiscal. whereYear/whereMonth
     * aplican funciones sobre la columna y descartan el índice — un full scan
     * en una tabla de 500k+ compras es fatal.
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
     * Trae las compras del período que pertenecen al Libro de Compras SAR.
     *
     * Incluye confirmadas Y anuladas: las anuladas aparecen en el detalle
     * (obligación SAR de correlativo sin huecos) pero se filtran en los
     * totales más adelante en buildSummary.
     *
     * EXCLUYE explícitamente los Recibos Internos (document_type='99'): son
     * compras informales sin CAI, no reconocidas por SAR y no deducibles.
     * El filtro usa `belongsToFiscalBook()` para que el día que SAR cambie
     * qué tipos entran al libro, el cambio sea en un solo lugar (el enum).
     *
     * Eager loading del supplier: sin esto, cada entrada dispararía una query
     * para resolver supplier.rtn y supplier.name → N+1 con 500 compras.
     *
     * @return Collection<int, Purchase>
     */
    private function fetchPurchases(string $from, string $to, ?int $establishmentId): Collection
    {
        $fiscalTypes = collect(SupplierDocumentType::cases())
            ->filter(fn (SupplierDocumentType $type) => $type->belongsToFiscalBook())
            ->map(fn (SupplierDocumentType $type) => $type->value)
            ->values()
            ->all();

        $query = Purchase::query()
            ->with('supplier:id,name,rtn')
            ->whereBetween('date', [$from, $to])
            ->whereIn('status', [PurchaseStatus::Confirmada, PurchaseStatus::Anulada])
            ->whereIn('document_type', $fiscalTypes);

        if ($establishmentId !== null) {
            $query->where('establishment_id', $establishmentId);
        }

        return $query
            ->orderBy('date')
            ->orderBy('document_type')
            ->orderBy('supplier_invoice_number')
            ->get();
    }

    /**
     * Resuelve el RTN del receptor (Diproma) desde CompanySetting.
     *
     * Si no hay configuración o el RTN está vacío devuelve string vacío
     * — será visible en el Excel y obligará al usuario a completar el
     * setup de la empresa antes de declarar al SAR.
     */
    private function resolveRtnReceptor(): string
    {
        $company = CompanySetting::current();
        return $company?->rtn ?? '';
    }

    /**
     * Normaliza y ordena todas las entradas del período.
     *
     * El orden del fetch (date asc, document_type asc, supplier_invoice_number
     * asc) ya deja los Purchase en el orden deseado. Aquí solo mapeamos a
     * value objects — no re-ordenamos para no gastar ciclos innecesarios.
     *
     * @param  Collection<int, Purchase>  $purchases
     * @return Collection<int, PurchaseBookEntry>
     */
    private function buildEntries(Collection $purchases, string $rtnReceptor): Collection
    {
        return $purchases
            ->map(fn (Purchase $purchase) => PurchaseBookEntry::fromPurchase($purchase, $rtnReceptor))
            ->values();
    }

    /**
     * Calcular el resumen del período segregado por tipo de documento.
     *
     * Los totales se calculan SOLO sobre documentos vigentes (no anulados).
     * Las anuladas se cuentan por separado para la trazabilidad pero NO
     * suman en los montos — esto es lo que el contador declarará al SAR.
     *
     * @param  Collection<int, Purchase>  $purchases
     */
    private function buildSummary(int $year, int $month, Collection $purchases): PurchaseBookSummary
    {
        $facturas      = $this->filterByType($purchases, SupplierDocumentType::Factura);
        $notasCredito  = $this->filterByType($purchases, SupplierDocumentType::NotaCredito);
        $notasDebito   = $this->filterByType($purchases, SupplierDocumentType::NotaDebito);

        [$factVig,   $factAnul]   = $this->splitByVoidStatus($facturas);
        [$ncVig,     $ncAnul]     = $this->splitByVoidStatus($notasCredito);
        [$ndVig,     $ndAnul]     = $this->splitByVoidStatus($notasDebito);

        return new PurchaseBookSummary(
            periodYear:  $year,
            periodMonth: $month,

            facturasEmitidasCount: $facturas->count(),
            facturasVigentesCount: $factVig->count(),
            facturasAnuladasCount: $factAnul->count(),
            facturasExento:  round((float) $factVig->sum('exempt_total'), 2),
            facturasGravado: round((float) $factVig->sum('taxable_total'), 2),
            facturasIsv:     round((float) $factVig->sum('isv'), 2),
            facturasTotal:   round((float) $factVig->sum('total'), 2),

            notasCreditoEmitidasCount: $notasCredito->count(),
            notasCreditoVigentesCount: $ncVig->count(),
            notasCreditoAnuladasCount: $ncAnul->count(),
            notasCreditoExento:  round((float) $ncVig->sum('exempt_total'), 2),
            notasCreditoGravado: round((float) $ncVig->sum('taxable_total'), 2),
            notasCreditoIsv:     round((float) $ncVig->sum('isv'), 2),
            notasCreditoTotal:   round((float) $ncVig->sum('total'), 2),

            notasDebitoEmitidasCount: $notasDebito->count(),
            notasDebitoVigentesCount: $ndVig->count(),
            notasDebitoAnuladasCount: $ndAnul->count(),
            notasDebitoExento:  round((float) $ndVig->sum('exempt_total'), 2),
            notasDebitoGravado: round((float) $ndVig->sum('taxable_total'), 2),
            notasDebitoIsv:     round((float) $ndVig->sum('isv'), 2),
            notasDebitoTotal:   round((float) $ndVig->sum('total'), 2),
        );
    }

    /**
     * @param  Collection<int, Purchase>  $purchases
     * @return Collection<int, Purchase>
     */
    private function filterByType(Collection $purchases, SupplierDocumentType $type): Collection
    {
        return $purchases->filter(fn (Purchase $p) => $p->document_type === $type)->values();
    }

    /**
     * Separa una colección de compras en [vigentes, anuladas].
     *
     * @param  Collection<int, Purchase>  $purchases
     * @return array{0: Collection<int, Purchase>, 1: Collection<int, Purchase>}
     */
    private function splitByVoidStatus(Collection $purchases): array
    {
        $vigentes = $purchases->filter(fn (Purchase $p) => $p->status !== PurchaseStatus::Anulada)->values();
        $anuladas = $purchases->filter(fn (Purchase $p) => $p->status === PurchaseStatus::Anulada)->values();

        return [$vigentes, $anuladas];
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
