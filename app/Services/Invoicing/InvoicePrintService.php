<?php

namespace App\Services\Invoicing;

use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Services\Fiscal\FiscalQrService;
use Illuminate\Support\Facades\Storage;

/**
 * Prepara los datos de una factura para la vista de impresion.
 *
 * Responsabilidad unica (SRP): orquestar la carga de relaciones, el formato
 * de montos y la integracion del QR/metadatos SAR para que la Blade
 * resources/views/invoices/print.blade.php sea puramente declarativa.
 *
 * NO calcula fiscalidad (eso vive en InvoiceService al emitir).
 * NO persiste nada (solo lee).
 * NO renderiza HTML (eso lo hace la Blade).
 */
class InvoicePrintService
{
    /**
     * Prefijo de la ruta publica de verificacion para facturas.
     * Se concatena con config('fiscal.verify_url_base') en FiscalQrService.
     * Debe coincidir con la ruta registrada en web.php como 'invoices.verify'.
     */
    private const VERIFY_PATH_PREFIX = 'facturas/verificar';

    public function __construct(
        private readonly FiscalQrService $qr,
    ) {}

    /**
     * Retorna el payload completo para la vista de impresion.
     *
     * La vista espera recibir este array via compact() o ['data' => ...].
     * Todos los montos vienen pre-formateados para evitar logica de presentacion
     * en la Blade (Law of Demeter aplicado: la view habla con strings ya listos,
     * no navega $invoice->items->first()->producto->... desde el template).
     */
    public function buildPrintPayload(Invoice $invoice): array
    {
        // Carga eager de relaciones necesarias en la vista. Si ya estan cargadas
        // no hace query extra (loadMissing es idempotente).
        $invoice->loadMissing([
            'sale.items.product',
            'establishment',
            'caiRange',
            'creator:id,name',
        ]);

        return [
            'invoice'        => $invoice,
            'items'          => $this->mapItemsForView($invoice),
            'totals'         => $this->buildTotals($invoice),
            'company'        => $this->buildCompanyBlock($invoice),
            'customer'       => $this->buildCustomerBlock($invoice),
            'cai'            => $this->buildCaiBlock($invoice),
            'seller'         => $invoice->creator?->name ?? '',
            // Método de pago: viene de Sale (la factura no lo persiste como
            // snapshot porque no es dato fiscal SAR). Usamos el getLabel()
            // del enum PaymentMethod para obtener "Efectivo", "Tarjeta de
            // crédito", etc. ya localizados. Sale viene eager-loaded arriba,
            // así que esto no agrega query extra.
            'paymentMethod'  => $invoice->sale?->payment_method?->getLabel() ?? '',
            'qrSvg'          => $this->qr->generateSvg(
                $invoice->integrity_hash ?? '',
                self::VERIFY_PATH_PREFIX,
            ),
            'verifyUrl'      => $this->qr->buildVerificationUrl(
                $invoice->integrity_hash ?? '',
                self::VERIFY_PATH_PREFIX,
            ),
            'software'       => $this->buildSoftwareMetadata(),
            'footerLegend'   => (string) config('fiscal.footer_legend'),
            'isVoid'         => (bool) $invoice->is_void,
        ];
    }

    /**
     * Mapea los items de la venta al formato plano que espera la tabla de la Blade.
     * Aplica Law of Demeter: la vista NO navega relaciones, recibe el DTO listo.
     */
    private function mapItemsForView(Invoice $invoice): array
    {
        $sale = $invoice->sale;

        if (! $sale || $sale->items->isEmpty()) {
            return [];
        }

        return $sale->items->map(function ($item) {
            $unitPrice = (float) $item->unit_price;
            $quantity  = (float) $item->quantity;
            $lineTotal = $unitPrice * $quantity;

            return [
                'description'    => $item->product?->name ?? $item->description ?? 'Producto',
                'sku'            => $item->product?->sku,
                'quantity'       => $this->formatQuantity($quantity),
                'unit_price'     => $this->formatMoney($unitPrice),
                'line_total'     => $this->formatMoney($lineTotal),
                'tax_type'       => $item->tax_type instanceof \App\Enums\TaxType
                    ? $item->tax_type->value
                    : (string) $item->tax_type,
            ];
        })->toArray();
    }

    /**
     * Totales formateados para el pie de la factura.
     * Usa los snapshots de la Invoice (NO recalcula desde items).
     */
    private function buildTotals(Invoice $invoice): array
    {
        return [
            'subtotal'       => $this->formatMoney((float) $invoice->subtotal),
            'exempt'         => $this->formatMoney((float) $invoice->exempt_total),
            'taxable'        => $this->formatMoney((float) $invoice->taxable_total),
            'isv'            => $this->formatMoney((float) $invoice->isv),
            'discount'       => $this->formatMoney((float) $invoice->discount),
            'total'          => $this->formatMoney((float) $invoice->total),
            'total_in_words' => $this->amountToWords((float) $invoice->total),
            'has_discount'   => (float) $invoice->discount > 0,
            'has_exempt'     => (float) $invoice->exempt_total > 0,
        ];
    }

    /**
     * Convierte un monto en lempiras a su representación en palabras.
     * Formato: "MIL QUINIENTOS LEMPIRAS CON 50/100".
     *
     * Cobertura: 0 hasta 999,999,999.99 (suficiente para facturación comercial).
     * El alcance se limita a nueve cifras enteras porque una factura por encima
     * de mil millones de lempiras es un caso que amerita rediseño del helper.
     */
    private function amountToWords(float $amount): string
    {
        $integer  = (int) floor($amount);
        $decimals = (int) round(($amount - $integer) * 100);

        $words = $integer === 0
            ? 'CERO'
            : $this->integerToSpanishWords($integer);

        return sprintf('%s LEMPIRAS CON %02d/100', trim($words), $decimals);
    }

    /**
     * Convierte un entero a palabras en español (hasta 999,999,999).
     * Implementación recursiva por escalas (millones → miles → unidades).
     */
    private function integerToSpanishWords(int $n): string
    {
        if ($n < 0) {
            return 'MENOS ' . $this->integerToSpanishWords(abs($n));
        }

        if ($n >= 1_000_000) {
            $millions = intdiv($n, 1_000_000);
            $rest     = $n % 1_000_000;
            $prefix   = $millions === 1 ? 'UN MILLON' : $this->integerToSpanishWords($millions) . ' MILLONES';

            return $rest === 0 ? $prefix : $prefix . ' ' . $this->integerToSpanishWords($rest);
        }

        if ($n >= 1_000) {
            $thousands = intdiv($n, 1_000);
            $rest      = $n % 1_000;
            $prefix    = $thousands === 1 ? 'MIL' : $this->integerToSpanishWords($thousands) . ' MIL';

            return $rest === 0 ? $prefix : $prefix . ' ' . $this->integerToSpanishWords($rest);
        }

        return $this->hundredsToWords($n);
    }

    /**
     * Convierte un número de 0 a 999 a palabras en español.
     * Centralizado para que tanto la escala de miles como la de millones
     * reusen exactamente la misma gramática (apócopes incluidas: "veintiún",
     * "un", "cien" vs "ciento", etc.).
     */
    private function hundredsToWords(int $n): string
    {
        static $units = [
            0 => '',        1 => 'UNO',        2 => 'DOS',       3 => 'TRES',      4 => 'CUATRO',
            5 => 'CINCO',   6 => 'SEIS',       7 => 'SIETE',     8 => 'OCHO',      9 => 'NUEVE',
            10 => 'DIEZ',   11 => 'ONCE',      12 => 'DOCE',     13 => 'TRECE',    14 => 'CATORCE',
            15 => 'QUINCE', 16 => 'DIECISEIS', 17 => 'DIECISIETE',18 => 'DIECIOCHO',19 => 'DIECINUEVE',
            20 => 'VEINTE',
        ];

        static $tens = [
            2 => 'VEINTI', 3 => 'TREINTA', 4 => 'CUARENTA', 5 => 'CINCUENTA',
            6 => 'SESENTA', 7 => 'SETENTA', 8 => 'OCHENTA', 9 => 'NOVENTA',
        ];

        static $hundreds = [
            1 => 'CIENTO',       2 => 'DOSCIENTOS',    3 => 'TRESCIENTOS',
            4 => 'CUATROCIENTOS',5 => 'QUINIENTOS',    6 => 'SEISCIENTOS',
            7 => 'SETECIENTOS',  8 => 'OCHOCIENTOS',   9 => 'NOVECIENTOS',
        ];

        if ($n === 0) {
            return '';
        }

        if ($n === 100) {
            return 'CIEN';
        }

        $h = intdiv($n, 100);
        $rest = $n % 100;

        $parts = [];
        if ($h > 0) {
            $parts[] = $hundreds[$h];
        }

        if ($rest > 0) {
            if ($rest <= 20) {
                $parts[] = $units[$rest];
            } else {
                $t = intdiv($rest, 10);
                $u = $rest % 10;

                if ($t === 2) {
                    // VEINTIUNO, VEINTIDOS, ... (sin espacio)
                    $parts[] = $u === 0 ? 'VEINTE' : $tens[2] . $units[$u];
                } else {
                    $parts[] = $u === 0 ? $tens[$t] : $tens[$t] . ' Y ' . $units[$u];
                }
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Bloque del emisor.
     *
     * Datos fiscales inmutables (nombre, RTN, dirección, teléfono, email) se
     * leen del snapshot (company_* en Invoice) — es la verdad legal al momento
     * de emitir. Si la empresa cambia de dirección o RTN, facturas antiguas
     * siguen mostrando los datos originales.
     *
     * Datos de branding (logo, rubro/actividad económica) NO requieren snapshot
     * legal por SAR, se leen en tiempo de render desde CompanySetting::current().
     * Esto hace que si cambiamos el logo, las reimpresiones reflejen el nuevo
     * branding (comportamiento deseado para identidad visual).
     */
    private function buildCompanyBlock(Invoice $invoice): array
    {
        $settings = CompanySetting::current();

        return [
            // Snapshot fiscal (verdad legal inmutable)
            'name'    => (string) $invoice->company_name,
            'rtn'     => (string) $invoice->company_rtn,
            'address' => (string) $invoice->company_address,
            'phone'   => (string) $invoice->company_phone,
            'email'   => (string) $invoice->company_email,
            // Branding actual (no requiere snapshot por SAR)
            // Defensa: validamos que el archivo EXISTA en disco antes de
            // devolver la URL. Evita renderizar un <img> roto si el path
            // quedó huérfano (logo borrado, storage:link faltante, etc.).
            'logo_url'      => $this->resolveLogoUrl($settings->logo_path),
            'business_type' => (string) ($settings->business_type ?? ''),
        ];
    }

    /**
     * Bloque del receptor: tambien desde snapshot (customer_name/rtn en Invoice).
     * Si customer_name esta vacio, se cae a "Consumidor Final".
     */
    private function buildCustomerBlock(Invoice $invoice): array
    {
        return [
            'name' => $invoice->customer_name ?: 'Consumidor Final',
            'rtn'  => $invoice->customer_rtn ?: null,
        ];
    }

    /**
     * Bloque fiscal CAI. Maneja el caso "sin CAI" (referencia interna).
     *
     * range_from/range_to se construyen a partir de las columnas reales del
     * modelo CaiRange (range_start / range_end) concatenadas con el prefix
     * y zero-padding a 8 dígitos, replicando el formato exacto del
     * invoice_number autorizado por SAR. Ej: "001-001-01-00000001".
     */
    private function buildCaiBlock(Invoice $invoice): array
    {
        $caiRange = $invoice->caiRange;

        return [
            'number'          => (string) $invoice->cai,
            'invoice_number'  => (string) $invoice->invoice_number,
            'emission_point'  => (string) $invoice->emission_point,
            'expiration_date' => $invoice->cai_expiration_date?->format('d/m/Y'),
            'range_from'      => $this->formatAuthorizedNumber($caiRange?->prefix, $caiRange?->range_start),
            'range_to'        => $this->formatAuthorizedNumber($caiRange?->prefix, $caiRange?->range_end),
            'without_cai'     => (bool) $invoice->without_cai,
        ];
    }

    /**
     * Formatea un número autorizado SAR: "{prefix}-{correlativo_8_digitos}".
     * Retorna null si falta prefix o número (ej: factura sin CAI).
     */
    private function formatAuthorizedNumber(?string $prefix, ?int $number): ?string
    {
        if (empty($prefix) || $number === null) {
            return null;
        }

        return $prefix . '-' . str_pad((string) $number, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Metadatos del software (Acuerdo 481-2017) para el pie de la factura
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
     * Resuelve URL pública del logo, verificando que el archivo EXISTA.
     *
     * Fail-fast defensivo: si logo_path apunta a un archivo que no está en
     * storage (limpieza manual, migración incompleta, storage:link faltante),
     * retornamos null para que la Blade renderice el placeholder en lugar
     * de un <img> roto.
     */
    private function resolveLogoUrl(?string $logoPath): ?string
    {
        if (empty($logoPath)) {
            return null;
        }

        if (! Storage::disk('public')->exists($logoPath)) {
            return null;
        }

        return Storage::disk('public')->url($logoPath);
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
