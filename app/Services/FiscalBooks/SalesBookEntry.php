<?php

namespace App\Services\FiscalBooks;

use App\Models\CreditNote;
use App\Models\Invoice;
use Carbon\CarbonImmutable;

/**
 * Línea única del Libro de Ventas SAR.
 *
 * Normaliza Invoice (tipo 01) y CreditNote (tipo 03) a una interfaz común para
 * que la hoja de detalle pueda iterar sin condicionales. Es value object
 * inmutable — una vez construido no muta, lo que previene bugs por mutación
 * accidental al mapear a celdas Excel.
 *
 * El RTN del receptor se normaliza a "0000000000000" (13 ceros) cuando es NULL
 * porque es la convención SAR para consumidor final en libros de ventas.
 */
final class SalesBookEntry
{
    public function __construct(
        public readonly CarbonImmutable $fecha,
        public readonly string $tipoDocumento,   // '01' factura, '03' nota crédito
        public readonly string $numero,
        public readonly ?string $cai,
        public readonly string $rtnEmisor,
        public readonly string $rtnReceptor,     // '0000000000000' si consumidor final
        public readonly string $nombreReceptor,
        public readonly float $exento,
        public readonly float $gravado,
        public readonly float $isv,
        public readonly float $total,
        public readonly bool $anulada,
        public readonly ?string $referenciaOrigen, // solo para notas de crédito
    ) {}

    /**
     * Construir una entrada a partir de una factura (tipo 01).
     */
    public static function fromInvoice(Invoice $invoice): self
    {
        return new self(
            fecha: CarbonImmutable::instance($invoice->invoice_date),
            tipoDocumento: '01',
            numero: $invoice->invoice_number,
            cai: $invoice->cai,
            rtnEmisor: $invoice->company_rtn,
            rtnReceptor: self::normalizeReceptorRtn($invoice->customer_rtn),
            nombreReceptor: $invoice->customer_name,
            exento: (float) $invoice->exempt_total,
            gravado: (float) $invoice->taxable_total,
            isv: (float) $invoice->isv,
            total: (float) $invoice->total,
            anulada: (bool) $invoice->is_void,
            referenciaOrigen: null,
        );
    }

    /**
     * Construir una entrada a partir de una nota de crédito (tipo 03).
     */
    public static function fromCreditNote(CreditNote $note): self
    {
        return new self(
            fecha: CarbonImmutable::instance($note->credit_note_date),
            tipoDocumento: '03',
            numero: $note->credit_note_number,
            cai: $note->cai,
            rtnEmisor: $note->company_rtn,
            rtnReceptor: self::normalizeReceptorRtn($note->customer_rtn),
            nombreReceptor: $note->customer_name,
            exento: (float) $note->exempt_total,
            gravado: (float) $note->taxable_total,
            isv: (float) $note->isv,
            total: (float) $note->total,
            anulada: (bool) $note->is_void,
            referenciaOrigen: $note->original_invoice_number,
        );
    }

    /**
     * ¿Esta entrada computa para los totales del período?
     *
     * Las facturas anuladas aparecen en el detalle (obligación SAR de mostrar
     * el correlativo completo sin huecos) pero NO suman en los totales del
     * resumen ni en la declaración ISV del período.
     */
    public function cuentaEnTotales(): bool
    {
        return ! $this->anulada;
    }

    /**
     * Etiqueta humana del estado para la columna "Estado" del Excel.
     */
    public function estadoLabel(): string
    {
        return $this->anulada ? 'Anulada' : 'Vigente';
    }

    /**
     * Etiqueta humana del tipo de documento para la columna "Tipo" del Excel.
     */
    public function tipoLabel(): string
    {
        return match ($this->tipoDocumento) {
            '01' => '01 - Factura',
            '03' => '03 - Nota de Crédito',
            default => $this->tipoDocumento,
        };
    }

    /**
     * RTN normalizado para consumidor final: "0000000000000" si es null o vacío.
     *
     * El SAR acepta este formato en el Libro de Ventas; dejarlo vacío genera
     * warning en el portal eSAR al validar el archivo.
     */
    private static function normalizeReceptorRtn(?string $rtn): string
    {
        return empty($rtn) ? '0000000000000' : $rtn;
    }
}
