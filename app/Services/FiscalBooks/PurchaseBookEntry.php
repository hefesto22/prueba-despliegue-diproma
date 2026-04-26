<?php

namespace App\Services\FiscalBooks;

use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Models\Purchase;
use Carbon\CarbonImmutable;

/**
 * Línea única del Libro de Compras SAR.
 *
 * Normaliza un Purchase (documento recibido del proveedor — tipo 01 Factura,
 * 03 Nota de Crédito o 04 Nota de Débito) a una interfaz común para que la
 * hoja de detalle pueda iterar sin condicionales por tipo. Es value object
 * inmutable — una vez construido no muta, lo que previene bugs por mutación
 * accidental al mapear a celdas Excel.
 *
 * Diferencia clave con SalesBookEntry:
 *   - En el Libro de Ventas el "emisor" es la empresa (Diproma) y el "receptor"
 *     es el cliente.
 *   - En el Libro de Compras el "emisor" es el proveedor y el "receptor" es
 *     la empresa (Diproma) — perspectiva invertida.
 *
 * El RTN del proveedor es obligatorio en SAR (no aplica normalización a
 * "0000000000000" como en ventas). Si un proveedor no tiene RTN es un error
 * de data que debe corregirse en el módulo de proveedores.
 */
final class PurchaseBookEntry
{
    public function __construct(
        public readonly CarbonImmutable $fecha,
        public readonly string $tipoDocumento,            // '01', '03', '04'
        public readonly string $numeroInterno,            // COMP-2026-00001 (nuestro correlativo)
        public readonly string $numeroDocumentoProveedor, // supplier_invoice_number (SAR)
        public readonly ?string $cai,                     // CAI del documento del proveedor
        public readonly string $rtnProveedor,
        public readonly string $nombreProveedor,
        public readonly string $rtnReceptor,              // RTN de Diproma (empresa)
        public readonly float $exento,
        public readonly float $gravado,
        public readonly float $isv,
        public readonly float $total,
        public readonly bool $anulada,
    ) {}

    /**
     * Construir una entrada a partir de un Purchase.
     *
     * El Purchase debe venir con las relaciones `supplier` cargadas — el caller
     * (PurchaseBookService) se encarga de hacer eager loading para evitar N+1.
     *
     * El RTN del receptor (Diproma) se toma del CompanySettings — se resuelve
     * fuera de este método y se pasa explícitamente para mantener al
     * value object puro (sin acceso a DB).
     */
    public static function fromPurchase(Purchase $purchase, string $rtnReceptor): self
    {
        return new self(
            fecha: CarbonImmutable::instance($purchase->date),
            tipoDocumento: $purchase->document_type?->value ?? SupplierDocumentType::Factura->value,
            numeroInterno: $purchase->purchase_number,
            numeroDocumentoProveedor: $purchase->supplier_invoice_number ?? '',
            cai: $purchase->supplier_cai,
            rtnProveedor: $purchase->supplier->rtn ?? '',
            nombreProveedor: $purchase->supplier->name ?? '',
            rtnReceptor: $rtnReceptor,
            exento: (float) $purchase->exempt_total,
            gravado: (float) $purchase->taxable_total,
            isv: (float) $purchase->isv,
            total: (float) $purchase->total,
            anulada: $purchase->status === PurchaseStatus::Anulada,
        );
    }

    /**
     * ¿Esta entrada computa para los totales del período?
     *
     * Las compras anuladas aparecen en el detalle (obligación SAR de mostrar
     * el correlativo completo sin huecos) pero NO suman en los totales del
     * resumen ni en el crédito fiscal del período.
     */
    public function cuentaEnTotales(): bool
    {
        return ! $this->anulada;
    }

    /**
     * ¿Este documento suma al crédito fiscal (true) o lo resta (false)?
     *
     * Factura y ND suman. NC resta. Se ignora si está anulada — el caller
     * debe combinarlo con `cuentaEnTotales()` para el cálculo final.
     */
    public function aportaCreditoFiscal(): bool
    {
        return SupplierDocumentType::tryFrom($this->tipoDocumento)?->addsToCreditoFiscal() ?? true;
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
            '04' => '04 - Nota de Débito',
            default => $this->tipoDocumento,
        };
    }
}
