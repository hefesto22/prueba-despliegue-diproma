<?php

namespace App\Services\Invoicing;

use App\Enums\DocumentType;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\Sale;
use App\Services\Invoicing\Contracts\ResuelveCorrelativoFactura;
use App\Services\Invoicing\Totals\InvoiceTotalsCalculator;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(
        private readonly ResuelveCorrelativoFactura $resolver,
        private readonly InvoiceTotalsCalculator $totalsCalculator,
    ) {}

    /**
     * Generar factura fiscal a partir de una venta completada.
     *
     * @param  Sale          $sale             Venta ya procesada.
     * @param  bool          $withoutCai       True para factura sin CAI (referencia interna).
     * @param  int|null      $establishmentId  Establecimiento emisor. En modo centralizado es opcional
     *                                         (cae a matriz). En modo por_sucursal es obligatorio.
     * @param  DocumentType  $documentType     Tipo SAR; por defecto DocumentType::Factura ('01').
     */
    public function generateFromSale(
        Sale $sale,
        bool $withoutCai = false,
        ?int $establishmentId = null,
        DocumentType $documentType = DocumentType::Factura,
    ): Invoice {
        $sale->load('items');
        $company = CompanySetting::current();

        return DB::transaction(function () use ($sale, $company, $withoutCai, $establishmentId, $documentType) {
            // Datos fiscales: resolver via interfaz o generar referencia sin CAI
            $invoiceNumber = null;
            $cai = null;
            $caiRangeId = null;
            $caiExpirationDate = null;
            $resolvedEstablishmentId = $establishmentId;
            $emissionPoint = null;

            if ($withoutCai) {
                // Factura sin CAI: referencia interna basada en el número de venta
                $invoiceNumber = 'SC-' . $sale->sale_number;

                // Para reportes, intentamos al menos registrar el establishment
                if (! $resolvedEstablishmentId) {
                    $main = $company->mainEstablishment();
                    if ($main) {
                        $resolvedEstablishmentId = $main->id;
                        $emissionPoint = $main->emission_point;
                    }
                }
            } else {
                $correlativo = $this->resolver->siguiente($documentType, $establishmentId);

                $invoiceNumber = $correlativo->documentNumber;
                $cai = $correlativo->cai;
                $caiRangeId = $correlativo->caiRangeId;
                $caiExpirationDate = $correlativo->caiExpirationDate;
                $resolvedEstablishmentId = $correlativo->establishmentId;
                $emissionPoint = $correlativo->emissionPoint;
            }

            // Desglose fiscal correcto (refactor E.2.A4): taxable_total y
            // exempt_total quedan segregados según tax_type de cada SaleItem.
            // Antes de este refactor, taxable_total persistía Sale.subtotal
            // (gravado + exento sumados), causando que SalesBookService
            // reportara mal las columnas Ventas Gravadas / Ventas Exentas
            // del Libro de Ventas SAR para cualquier factura con exento.
            $totals = $this->totalsCalculator->calculate($sale);

            $invoice = Invoice::create([
                'sale_id' => $sale->id,
                'cai_range_id' => $caiRangeId,
                'establishment_id' => $resolvedEstablishmentId,
                'invoice_number' => $invoiceNumber,
                'cai' => $cai,
                'emission_point' => $emissionPoint,
                'invoice_date' => $sale->date ?? now(),
                'cai_expiration_date' => $caiExpirationDate,

                // Snapshot emisor
                'company_name' => $company->display_name,
                'company_rtn' => $company->rtn,
                'company_address' => $company->full_address,
                'company_phone' => $company->phone,
                'company_email' => $company->email,

                // Snapshot receptor
                'customer_name' => $sale->customer_name ?: 'Consumidor Final',
                'customer_rtn' => $sale->customer_rtn,

                // Totales fiscales:
                //   - subtotal/isv/total → snapshot directo del Sale (el Invoice
                //     es snapshot del Sale, no recalcula).
                //   - taxable_total/exempt_total → del InvoiceTotalsCalculator,
                //     que segrega correctamente las bases por TaxType. Antes del
                //     refactor E.2.A4, taxable_total persistía Sale.subtotal
                //     (gravado + exento), causando que SalesBookService reportara
                //     mal las columnas Ventas Gravadas / Ventas Exentas del Libro
                //     SAR para cualquier factura con producto exento.
                'subtotal' => $sale->subtotal,
                'exempt_total' => $totals->exemptTotal,
                'taxable_total' => $totals->taxableTotal,
                'isv' => $sale->isv,
                'discount' => $sale->discount_amount ?? 0,
                'total' => $sale->total,

                // Estado: explícitos para que el modelo en memoria refleje la realidad del DB
                // (Eloquent no hidrata los defaults del schema automáticamente).
                'is_void' => false,
                'without_cai' => $withoutCai,
                'created_by' => auth()->id(),
            ]);

            // Sellado del documento fiscal:
            //   - emitted_at: instante legal de emisión (separado de created_at).
            //   - integrity_hash: identificador público inmutable para el QR de
            //     verificación (/facturas/verificar/{hash}).
            // Se asignan DESPUÉS del create porque el hash depende del id generado.
            // No se incluye is_void en el hash: anular cambia el estado, no el
            // documento original — los QR impresos siguen siendo verificables.
            $invoice->emitted_at = now();
            $invoice->integrity_hash = $this->calculateIntegrityHash($invoice);
            $invoice->save();

            return $invoice;
        });
    }

    /**
     * Anular una factura.
     *
     * @deprecated Use el flujo de cascade: SaleService::cancel($sale).
     *             Este método llama directamente a Invoice::void() sin
     *             restaurar stock ni validar el período fiscal. Solo se
     *             mantiene por compatibilidad con tests existentes.
     * @internal  No llamar desde UI ni controladores — siempre pasar por
     *            FiscalPeriodService::assertCanVoidInvoice() +
     *            SaleService::cancel().
     */
    public function voidInvoice(Invoice $invoice): void
    {
        $invoice->void();
    }

    /**
     * Hash de integridad determinista del documento fiscal.
     *
     * Se calcula sobre el snapshot inmutable + el id ya asignado por la DB.
     * Sirve como identificador público del QR de verificación, evitando
     * exponer el id secuencial directamente en la URL.
     *
     * Excluye campos mutables (is_void, emitted_at): el hash identifica el
     * documento emitido, no su estado posterior. Anular una factura NO debe
     * cambiar su hash — los QR ya impresos siguen siendo verificables y
     * mostrarán el estado "ANULADA" en la ruta pública.
     */
    private function calculateIntegrityHash(Invoice $invoice): string
    {
        return hash('sha256', json_encode([
            'id'             => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'cai'            => $invoice->cai,
            'company_rtn'    => $invoice->company_rtn,
            'customer_rtn'   => $invoice->customer_rtn,
            'total'          => (string) $invoice->total,
            'invoice_date'   => $invoice->invoice_date?->toDateString(),
        ]));
    }
}
