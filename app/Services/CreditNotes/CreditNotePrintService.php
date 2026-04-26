<?php

namespace App\Services\CreditNotes;

use App\Models\CreditNote;
use App\Services\Fiscal\FiscalQrService;

/**
 * Prepara los datos de una Nota de Credito para la vista de impresion.
 *
 * Responsabilidad unica (SRP): orquestar la carga de relaciones, el formato
 * de montos y la integracion del QR/metadatos SAR para que la Blade
 * resources/views/credit-notes/print.blade.php sea puramente declarativa.
 *
 * Simetrico a InvoicePrintService — adaptado a la estructura de CreditNote:
 *   - Items vienen de $creditNote->items (no de sale->items).
 *   - Incluye bloque de razon + notas (solo en NC).
 *   - Incluye snapshot de factura origen (solo en NC).
 *   - Fecha del documento: credit_note_date.
 *
 * NO calcula fiscalidad (eso vive en CreditNoteService al emitir).
 * NO persiste nada (solo lee).
 * NO renderiza HTML (eso lo hace la Blade).
 */
class CreditNotePrintService
{
    /**
     * Prefijo de la ruta publica de verificacion para notas de credito.
     * Se concatena con config('fiscal.verify_url_base') en FiscalQrService.
     * Debe coincidir con la ruta registrada en web.php como 'credit-notes.verify'.
     */
    private const VERIFY_PATH_PREFIX = 'notas-credito/verificar';

    public function __construct(
        private readonly FiscalQrService $qr,
    ) {}

    /**
     * Retorna el payload completo para la vista de impresion.
     *
     * La vista espera recibir este array via compact() o ['data' => ...].
     * Todos los montos vienen pre-formateados para evitar logica de presentacion
     * en la Blade (Law of Demeter aplicado: la view habla con strings ya listos,
     * no navega $creditNote->items->first()->product->... desde el template).
     */
    public function buildPrintPayload(CreditNote $creditNote): array
    {
        // Carga eager de relaciones necesarias en la vista. Si ya estan cargadas
        // no hace query extra (loadMissing es idempotente).
        $creditNote->loadMissing([
            'items.product',
            'establishment',
            'caiRange',
        ]);

        return [
            'creditNote'     => $creditNote,
            'items'          => $this->mapItemsForView($creditNote),
            'totals'         => $this->buildTotals($creditNote),
            'company'        => $this->buildCompanyBlock($creditNote),
            'customer'       => $this->buildCustomerBlock($creditNote),
            'cai'            => $this->buildCaiBlock($creditNote),
            'reason'         => $this->buildReasonBlock($creditNote),
            'originalInvoice'=> $this->buildOriginalInvoiceBlock($creditNote),
            'qrSvg'          => $this->qr->generateSvg(
                $creditNote->integrity_hash ?? '',
                self::VERIFY_PATH_PREFIX,
            ),
            'verifyUrl'      => $this->qr->buildVerificationUrl(
                $creditNote->integrity_hash ?? '',
                self::VERIFY_PATH_PREFIX,
            ),
            'software'       => $this->buildSoftwareMetadata(),
            'footerLegend'   => (string) config('fiscal.footer_legend'),
            'isVoid'         => (bool) $creditNote->is_void,
        ];
    }

    /**
     * Mapea los items de la NC al formato plano que espera la tabla de la Blade.
     * Aplica Law of Demeter: la vista NO navega relaciones, recibe el DTO listo.
     *
     * Nota: los items de CN se persisten con precio sellado al emitir — el
     * unit_price es el mismo que el SaleItem origen (no se recalcula).
     */
    private function mapItemsForView(CreditNote $creditNote): array
    {
        if ($creditNote->items->isEmpty()) {
            return [];
        }

        return $creditNote->items->map(function ($item) {
            $unitPrice = (float) $item->unit_price;
            $quantity  = (float) $item->quantity;
            $lineTotal = (float) $item->total;

            return [
                'description' => $item->product?->name ?? 'Producto',
                'sku'         => $item->product?->sku,
                'quantity'    => $this->formatQuantity($quantity),
                'unit_price'  => $this->formatMoney($unitPrice),
                'line_total'  => $this->formatMoney($lineTotal),
                'tax_type'    => $item->tax_type instanceof \App\Enums\TaxType
                    ? $item->tax_type->value
                    : (string) $item->tax_type,
            ];
        })->toArray();
    }

    /**
     * Totales formateados para el pie de la NC.
     * Usa los snapshots de la CreditNote (NO recalcula desde items).
     *
     * CreditNote NO tiene campo `discount` — el descuento proporcional de la
     * factura origen se aplica en CreditNoteTotalsCalculator al momento de
     * emitir, quedando reflejado directamente en taxable_total/isv.
     */
    private function buildTotals(CreditNote $creditNote): array
    {
        return [
            'subtotal'   => $this->formatMoney((float) $creditNote->subtotal),
            'exempt'     => $this->formatMoney((float) $creditNote->exempt_total),
            'taxable'    => $this->formatMoney((float) $creditNote->taxable_total),
            'isv'        => $this->formatMoney((float) $creditNote->isv),
            'total'      => $this->formatMoney((float) $creditNote->total),
            'has_exempt' => (float) $creditNote->exempt_total > 0,
        ];
    }

    /**
     * Bloque del emisor: se arma desde el snapshot fiscal (company_* en CreditNote),
     * nunca desde CompanySetting::current() — el snapshot es la verdad legal.
     */
    private function buildCompanyBlock(CreditNote $creditNote): array
    {
        return [
            'name'    => (string) $creditNote->company_name,
            'rtn'     => (string) $creditNote->company_rtn,
            'address' => (string) $creditNote->company_address,
            'phone'   => (string) $creditNote->company_phone,
            'email'   => (string) $creditNote->company_email,
        ];
    }

    /**
     * Bloque del receptor: desde snapshot (customer_name/rtn en CreditNote).
     * Si customer_name esta vacio, se cae a "Consumidor Final".
     */
    private function buildCustomerBlock(CreditNote $creditNote): array
    {
        return [
            'name' => $creditNote->customer_name ?: 'Consumidor Final',
            'rtn'  => $creditNote->customer_rtn ?: null,
        ];
    }

    /**
     * Bloque fiscal CAI. Maneja el caso "sin CAI" (referencia interna).
     */
    private function buildCaiBlock(CreditNote $creditNote): array
    {
        $caiRange = $creditNote->caiRange;

        return [
            'number'             => (string) $creditNote->cai,
            'credit_note_number' => (string) $creditNote->credit_note_number,
            'emission_point'     => (string) $creditNote->emission_point,
            'expiration_date'    => $creditNote->cai_expiration_date?->format('d/m/Y'),
            'range_from'         => $caiRange?->range_from,
            'range_to'           => $caiRange?->range_to,
            'without_cai'        => (bool) $creditNote->without_cai,
        ];
    }

    /**
     * Bloque de razon legal de la NC (solo existe en CN, no en Invoice).
     * Muestra el label humano del enum + las notas explicativas cuando aplica.
     */
    private function buildReasonBlock(CreditNote $creditNote): array
    {
        $reason = $creditNote->reason;

        return [
            'code'  => $reason?->value,
            'label' => $reason?->getLabel() ?? '',
            'notes' => (string) ($creditNote->reason_notes ?? ''),
        ];
    }

    /**
     * Bloque de referencia a la factura origen. Usa el snapshot (original_invoice_*),
     * no la relacion invoice() — la NC sigue siendo valida incluso si la factura
     * origen se anulara a futuro (raro, pero el documento es autocontenido).
     */
    private function buildOriginalInvoiceBlock(CreditNote $creditNote): array
    {
        return [
            'number' => (string) $creditNote->original_invoice_number,
            'cai'    => (string) ($creditNote->original_invoice_cai ?? ''),
            'date'   => $creditNote->original_invoice_date?->format('d/m/Y'),
        ];
    }

    /**
     * Metadatos del software (Acuerdo 481-2017) para el pie de la NC
     * y para la declaracion SIISAR.
     */
    private function buildSoftwareMetadata(): array
    {
        return [
            'name'      => (string) config('fiscal.software.name'),
            'version'   => (string) config('fiscal.software.version'),
            'developer' => (string) config('fiscal.software.developer'),
            'structure' => (string) config('fiscal.structure'),
        ];
    }

    /**
     * Formato de moneda: "1,234.56" sin simbolo (el simbolo L va en la vista).
     */
    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, '.', ',');
    }

    /**
     * Cantidad: sin decimales si es entera, con decimales solo cuando aporta.
     * Evita mostrar "2.00 unidades" cuando basta "2".
     */
    private function formatQuantity(float $quantity): string
    {
        return floor($quantity) === $quantity
            ? (string) (int) $quantity
            : rtrim(rtrim(number_format($quantity, 3, '.', ','), '0'), '.');
    }
}
