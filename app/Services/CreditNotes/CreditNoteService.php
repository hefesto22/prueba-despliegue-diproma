<?php

namespace App\Services\CreditNotes;

use App\Enums\DocumentType;
use App\Models\CompanySetting;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\SaleItem;
use App\Services\CreditNotes\DTOs\EmitirNotaCreditoInput;
use App\Services\CreditNotes\Exceptions\CantidadYaAcreditadaException;
use App\Services\CreditNotes\Exceptions\FacturaAnuladaNoAcreditableException;
use App\Services\CreditNotes\Exceptions\FacturaWithoutCaiNoAcreditableException;
use App\Services\CreditNotes\Exceptions\NotaCreditoYaAnuladaException;
use App\Services\CreditNotes\Exceptions\StockInsuficienteParaAnularNCException;
use App\Services\CreditNotes\Totals\CreditNoteTotalsCalculator;
use App\Services\Invoicing\Contracts\ResuelveCorrelativoFactura;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Emisor de Notas de Crédito (SAR tipo '03').
 *
 * Orquesta el flujo fiscal end-to-end. Tres colaboradores con SRP:
 *   - {@see ResuelveCorrelativoFactura}       resuelve el siguiente número SAR.
 *   - {@see CreditNoteTotalsCalculator}        matemática fiscal (subtotal/ISV/total).
 *   - {@see CreditNoteInventoryProcessor}      kardex (entradas y reversiones).
 *
 * El servicio en sí solo se ocupa de:
 *   1. Atomicidad (la única clase que abre `DB::transaction`).
 *   2. Validaciones de elegibilidad fiscal (factura anulada, sin CAI).
 *   3. Validación acumulativa vs NCs previas no anuladas.
 *   4. Persistencia + sellado fiscal (emitted_at + integrity_hash).
 *
 * Refactor E.2.A3: extracción de `calcularTotales`, `reverseInventory` y
 * `revertInventoryForVoid` a colaboradores dedicados. La extracción además
 * corrigió un bug fiscal en el cálculo del ratio de descuento que sub-aplicaba
 * el descuento en facturas mix gravado/exento — detalle en el PHPDoc de
 * `CreditNoteTotalsCalculator`.
 *
 * Flujo atómico de emisión (DB::transaction):
 *   1. lockForUpdate sobre la factura origen (serializa NCs concurrentes).
 *   2. Validar factura: no anulada, no sin CAI.
 *   3. Cargar sale_items + validación acumulativa.
 *   4. Resolver correlativo SAR documentType '03'.
 *   5. Calcular totales (delegado al calculador).
 *   6. Crear CreditNote + CreditNoteItem[].
 *   7. Si la razón requiere reversión: registrar kardex (delegado al processor).
 *   8. Sellar emitted_at + integrity_hash.
 */
class CreditNoteService
{
    public function __construct(
        private readonly ResuelveCorrelativoFactura $resolver,
        private readonly CreditNoteTotalsCalculator $totalsCalculator,
        private readonly CreditNoteInventoryProcessor $inventoryProcessor,
    ) {}

    public function generateFromInvoice(EmitirNotaCreditoInput $input): CreditNote
    {
        return DB::transaction(function () use ($input) {
            // 1. Lock sobre la factura origen: serializa NCs concurrentes sobre
            //    la misma factura, permite paralelismo entre facturas distintas.
            /** @var Invoice $invoice */
            $invoice = Invoice::where('id', $input->invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Validar elegibilidad fiscal
            $this->assertInvoiceIsCredible($invoice);

            // 3. Cargar sale_items + validar pertenencia + validación acumulativa
            $saleItems = $this->loadSaleItems($invoice, $input->lineas);
            $this->assertCantidadesDisponibles($invoice, $input->lineas, $saleItems);

            // 4. Resolver correlativo SAR tipo Nota de Crédito (enum = código '03').
            //    Pasamos el enum — no el string — para que cualquier cambio de
            //    código SAR quede en un único lugar (DocumentType) y no haya
            //    magic strings regados en los servicios.
            $correlativo = $this->resolver->siguiente(
                DocumentType::NotaCredito,
                $invoice->establishment_id,
            );

            // 5. Calcular totales (calculador dedicado — aplica ratio de descuento
            //    de la factura origen y delega el desglose per-línea a
            //    SaleTaxCalculator para mantener una sola fuente de verdad
            //    fiscal en todo el sistema).
            $totales = $this->totalsCalculator->calculate($invoice, $input->lineas, $saleItems);

            // 6. Crear CreditNote + items
            $company = CompanySetting::current();
            $creditNote = CreditNote::create([
                'invoice_id'           => $invoice->id,
                'cai_range_id'         => $correlativo->caiRangeId,
                'establishment_id'     => $correlativo->establishmentId,

                'credit_note_number'   => $correlativo->documentNumber,
                'cai'                  => $correlativo->cai,
                'emission_point'       => $correlativo->emissionPoint,
                'credit_note_date'     => now()->toDateString(),
                'cai_expiration_date'  => $correlativo->caiExpirationDate,

                'reason'               => $input->reason,
                'reason_notes'         => $input->reasonNotes,

                // Snapshot emisor
                'company_name'         => $company->display_name,
                'company_rtn'          => $company->rtn,
                'company_address'      => $company->full_address,
                'company_phone'        => $company->phone,
                'company_email'        => $company->email,

                // Snapshot receptor (desde la factura, NO del cliente actual —
                // el receptor legal de la NC es quien aparece en la factura).
                'customer_name'        => $invoice->customer_name,
                'customer_rtn'         => $invoice->customer_rtn,

                // Snapshot factura origen
                'original_invoice_number' => $invoice->invoice_number,
                'original_invoice_cai'    => $invoice->cai,
                'original_invoice_date'   => $invoice->invoice_date,

                // Totales (positivos; el "crédito" es implícito por tipo doc).
                // `subtotal` mapea a la base gravada post-descuento por
                // convención del schema de NC (mismo patrón que Invoice).
                'subtotal'             => $totales->taxableTotal,
                'exempt_total'         => $totales->exemptTotal,
                'taxable_total'        => $totales->taxableTotal,
                'isv'                  => $totales->isv,
                'total'                => $totales->total,

                'is_void'              => false,
                'without_cai'          => false,
                'created_by'           => auth()->id(),
            ]);

            foreach ($totales->items as $itemData) {
                CreditNoteItem::create([
                    'credit_note_id' => $creditNote->id,
                    ...$itemData,
                ]);
            }

            // 7. Reversión de kardex si la razón lo exige (delegado al processor —
            //    consolida por product_id y reusa unit_cost histórico del
            //    SalidaVenta original).
            if ($input->reason->returnsToInventory()) {
                $this->inventoryProcessor->registerReturn(
                    invoice:    $invoice,
                    creditNote: $creditNote,
                    lineas:     $input->lineas,
                    saleItems:  $saleItems,
                );
            }

            // 8. Sellado fiscal. El trait LocksFiscalFieldsAfterEmission permite
            //    este primer sellado porque getOriginal('emitted_at') es null
            //    (la fila fue recién creada en el paso 6).
            $creditNote->emitted_at     = now();
            $creditNote->integrity_hash = $this->calculateIntegrityHash($creditNote);
            $creditNote->save();

            return $creditNote->fresh(['items.product', 'invoice']);
        });
    }

    /**
     * Anular una Nota de Crédito ya emitida.
     *
     * Flujo atómico (DB::transaction):
     *   1. lockForUpdate sobre la NC — serializa intentos concurrentes de
     *      anulación sobre la misma NC.
     *   2. Fail fast: si ya está anulada, lanzar NotaCreditoYaAnuladaException.
     *      La doble anulación duplicaría SalidaAnulacionNotaCredito y
     *      descuadraría kardex.
     *   3. Si la razón devolvió mercadería al inventario
     *      (CreditNoteReason::returnsToInventory()) → revertir kardex
     *      (delegado a CreditNoteInventoryProcessor::revertForVoid):
     *        a. Validar stock suficiente con lockForUpdate por producto.
     *        b. Si stock insuficiente → StockInsuficienteParaAnularNCException
     *           (no se permite stock negativo silencioso).
     *        c. Registrar SalidaAnulacionNotaCredito reusando unit_cost
     *           histórico del EntradaNotaCredito original.
     *   4. Marcar is_void = true. El trait LocksFiscalFieldsAfterEmission
     *      permite este cambio porque `is_void` está en la whitelist de
     *      campos actualizables post-emisión.
     *   5. El integrity_hash NO se recalcula — un QR ya impreso sigue
     *      verificable y la ruta pública mostrará banner "ANULADA".
     *
     * Command (CQRS): no retorna datos.
     *
     * @throws NotaCreditoYaAnuladaException             Si la NC ya fue anulada.
     * @throws StockInsuficienteParaAnularNCException    Si la mercadería devuelta
     *                                                    ya fue revendida y el
     *                                                    stock actual no alcanza
     *                                                    para revertir la entrada.
     */
    public function voidNotaCredito(CreditNote $creditNote): void
    {
        DB::transaction(function () use ($creditNote) {
            // 1. Lock sobre la NC — serializa intentos concurrentes.
            /** @var CreditNote $locked */
            $locked = CreditNote::where('id', $creditNote->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Fail fast dentro del lock: si otra transacción ya anuló,
            //    aquí veremos is_void=true y abortamos antes de tocar kardex.
            if ($locked->is_void) {
                throw new NotaCreditoYaAnuladaException(
                    creditNoteId:     $locked->id,
                    creditNoteNumber: $locked->credit_note_number,
                );
            }

            // 3. Revertir kardex solo si la razón generó EntradaNotaCredito.
            //    Razones "no físicas" (ej. ErrorFacturacion, AjustePrecio) no
            //    tocaron inventario al emitir, así que no hay nada que revertir.
            if ($locked->reason->returnsToInventory()) {
                $this->inventoryProcessor->revertForVoid($locked);
            }

            // 4. Marcar como anulada. El trait LocksFiscalFieldsAfterEmission
            //    permite este update porque `is_void` está en la whitelist.
            $locked->update(['is_void' => true]);
        });
    }

    // ─── Validaciones ────────────────────────────────────────

    private function assertInvoiceIsCredible(Invoice $invoice): void
    {
        if ($invoice->is_void) {
            throw new FacturaAnuladaNoAcreditableException(
                invoiceId:     $invoice->id,
                invoiceNumber: $invoice->invoice_number,
            );
        }

        if ($invoice->without_cai) {
            throw new FacturaWithoutCaiNoAcreditableException(
                invoiceId:     $invoice->id,
                invoiceNumber: $invoice->invoice_number,
            );
        }
    }

    /**
     * Cargar los SaleItems del input y validar que todos pertenecen a la
     * misma venta de la factura origen. Rechaza cualquier id ajeno.
     *
     * @param  list<\App\Services\CreditNotes\DTOs\LineaAcreditarInput>  $lineas
     * @return Collection<int, SaleItem>  Indexada por SaleItem::id
     */
    private function loadSaleItems(Invoice $invoice, array $lineas): Collection
    {
        $ids = array_map(fn ($l) => $l->saleItemId, $lineas);

        $saleItems = SaleItem::whereIn('id', $ids)
            ->where('sale_id', $invoice->sale_id)
            ->get()
            ->keyBy('id');

        $faltantes = array_diff($ids, $saleItems->keys()->all());
        if ($faltantes !== []) {
            throw new InvalidArgumentException(
                'sale_item_id(s) no pertenecen a la factura '
                . "{$invoice->invoice_number}: " . implode(', ', $faltantes)
            );
        }

        return $saleItems;
    }

    /**
     * Validación acumulativa: por cada línea, la cantidad solicitada no puede
     * exceder (cantidad vendida - cantidad ya acreditada en NCs previas no
     * anuladas sobre la misma factura).
     *
     * Lee en una sola query el acumulado previo por sale_item_id.
     *
     * @param  list<\App\Services\CreditNotes\DTOs\LineaAcreditarInput>  $lineas
     * @param  Collection<int, SaleItem>                                  $saleItems
     */
    private function assertCantidadesDisponibles(
        Invoice $invoice,
        array $lineas,
        Collection $saleItems,
    ): void {
        $saleItemIds = $saleItems->keys()->all();

        /** @var array<int, int> $yaAcreditado */
        $yaAcreditado = CreditNoteItem::query()
            ->join('credit_notes', 'credit_notes.id', '=', 'credit_note_items.credit_note_id')
            ->where('credit_notes.invoice_id', $invoice->id)
            ->where('credit_notes.is_void', false)
            ->whereIn('credit_note_items.sale_item_id', $saleItemIds)
            ->groupBy('credit_note_items.sale_item_id')
            ->selectRaw('credit_note_items.sale_item_id, SUM(credit_note_items.quantity) as total')
            ->pluck('total', 'sale_item_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        foreach ($lineas as $linea) {
            /** @var SaleItem $saleItem */
            $saleItem = $saleItems[$linea->saleItemId];

            $yaAcredit  = $yaAcreditado[$linea->saleItemId] ?? 0;
            $disponible = (int) $saleItem->quantity - $yaAcredit;

            if ($linea->quantity > $disponible) {
                throw new CantidadYaAcreditadaException(
                    saleItemId:   $linea->saleItemId,
                    productId:    $saleItem->product_id,
                    solicitada:   $linea->quantity,
                    disponible:   max(0, $disponible),
                    yaAcreditada: $yaAcredit,
                );
            }
        }
    }

    // ─── Integridad ──────────────────────────────────────────

    /**
     * Hash determinista del documento fiscal para el QR de verificación
     * pública. Excluye is_void intencionalmente: anular una NC NO invalida
     * el hash — un QR ya impreso sigue verificable y mostrará el estado
     * "ANULADA" en la ruta pública.
     */
    private function calculateIntegrityHash(CreditNote $creditNote): string
    {
        return hash('sha256', (string) json_encode([
            'id'                      => $creditNote->id,
            'credit_note_number'      => $creditNote->credit_note_number,
            'cai'                     => $creditNote->cai,
            'company_rtn'             => $creditNote->company_rtn,
            'customer_rtn'            => $creditNote->customer_rtn,
            'total'                   => (string) $creditNote->total,
            'credit_note_date'        => $creditNote->credit_note_date?->toDateString(),
            'original_invoice_number' => $creditNote->original_invoice_number,
        ]));
    }
}
