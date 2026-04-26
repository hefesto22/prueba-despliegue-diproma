<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Tipo de documento que el PROVEEDOR emite a Diproma.
 *
 * Códigos oficiales del Régimen de Facturación SAR (Acuerdo 189-2014):
 *   01 — Factura (documento principal, da crédito fiscal)
 *   03 — Nota de Crédito del proveedor (reduce crédito fiscal previo)
 *   04 — Nota de Débito del proveedor (incrementa crédito fiscal previo)
 *   99 — Otros documentos no tipificados. Uso interno: Recibo Interno para
 *        compras informales sin documento fiscal (proveedor sin CAI). NO
 *        entra al Libro de Compras SAR ni es deducible de ISR. Ver
 *        docs de RI en InternalReceiptNumberGenerator.
 *
 * NO confundir con los tipos emitidos POR Diproma (en `invoices`/`credit_notes`).
 * Este enum clasifica documentos RECIBIDOS — se almacena en `purchases.document_type`
 * y es obligatorio para el Libro de Compras SAR.
 *
 * Hoy `Factura` y `ReciboInterno` están en uso productivo. `NotaCredito` y
 * `NotaDebito` quedan declaradas pero sin módulo propio — cuando exista el
 * módulo de NC/ND de proveedores, `purchases.document_type` las discriminará
 * sin necesidad de migración.
 */
enum SupplierDocumentType: string implements HasLabel, HasColor, HasIcon
{
    case Factura = '01';
    case NotaCredito = '03';
    case NotaDebito = '04';
    case ReciboInterno = '99';

    public function getLabel(): string
    {
        return match ($this) {
            self::Factura => 'Factura (01)',
            self::NotaCredito => 'Nota de Crédito (03)',
            self::NotaDebito => 'Nota de Débito (04)',
            self::ReciboInterno => 'Recibo Interno — Sin CAI (99)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Factura => 'primary',
            self::NotaCredito => 'warning',
            self::NotaDebito => 'info',
            self::ReciboInterno => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Factura => 'heroicon-o-document-text',
            self::NotaCredito => 'heroicon-o-receipt-refund',
            self::NotaDebito => 'heroicon-o-receipt-percent',
            self::ReciboInterno => 'heroicon-o-document-minus',
        };
    }

    /**
     * Label corto (sin código) — útil para columnas de tabla donde el código
     * aparece en otra columna.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Factura => 'Factura',
            self::NotaCredito => 'Nota de Crédito',
            self::NotaDebito => 'Nota de Débito',
            self::ReciboInterno => 'Recibo Interno',
        };
    }

    /**
     * ¿Este documento requiere CAI del proveedor?
     *
     * Los documentos SAR (01/03/04) requieren CAI emitido por la SAR al proveedor.
     * El Recibo Interno (99) NO — es para compras informales sin respaldo fiscal.
     *
     * Filament usa este método para ocultar/requerir el campo supplier_cai y
     * cambiar las reglas de validación del supplier_invoice_number dinámicamente.
     */
    public function requiresCai(): bool
    {
        return match ($this) {
            self::Factura, self::NotaCredito, self::NotaDebito => true,
            self::ReciboInterno => false,
        };
    }

    /**
     * ¿Este documento suma al crédito fiscal del período?
     *
     * Factura y ND suman. NC resta. RI no participa del cálculo fiscal
     * (ni suma ni resta — queda fuera del Libro de Compras SAR).
     */
    public function addsToCreditoFiscal(): bool
    {
        return match ($this) {
            self::Factura, self::NotaDebito => true,
            self::NotaCredito, self::ReciboInterno => false,
        };
    }

    /**
     * ¿Este documento entra al Libro de Compras SAR?
     *
     * Solo los documentos con CAI válido entran al libro (01, 03, 04).
     * El RI no entra — SAR no lo reconoce como documento fiscal.
     */
    public function belongsToFiscalBook(): bool
    {
        return $this->requiresCai();
    }

    /**
     * ¿Este documento separa el ISV del precio total al calcular?
     *
     * Factura y NC/ND emitidas por el proveedor traen ISV explícito (o
     * implícito en el precio con ISV incluido). Hay que separarlo porque:
     *   - Es crédito fiscal SAR — se compensa contra el ISV cobrado en ventas
     *   - Va al Libro de Compras con desglose subtotal/ISV
     *   - El costo contable real (para ISR/inventario) es la base sin ISV
     *
     * El Recibo Interno NO separa ISV: el proveedor informal no emite
     * documento SAR, así que NO hay ISV deducible. El precio que el
     * operador ingresó es el precio final pagado, sin desglose fiscal.
     * Forzar el back-out en RI generaría un "ISV fantasma" no deducible
     * que distorsiona el crédito fiscal y baja artificialmente el costo
     * del inventario (un L 100 pagado quedaría como L 86.96 base + L 13.04
     * ISV no deducible — eso es data corruption contable).
     *
     * Esta regla es la fuente de verdad para PurchaseTotalsCalculator.
     */
    public function separatesIsv(): bool
    {
        return match ($this) {
            self::Factura, self::NotaCredito, self::NotaDebito => true,
            self::ReciboInterno => false,
        };
    }
}
