<?php

namespace App\Services\FiscalBooks;

/**
 * Resumen del Libro de Ventas SAR para un período mensual.
 *
 * Value object inmutable con los totales que se declaran al SAR:
 *   - Ventas brutas (facturas vigentes)
 *   - Créditos (notas de crédito vigentes, que restan)
 *   - Venta neta del período = facturas - notas de crédito
 *
 * Las anuladas se cuentan por separado (obligación de mostrar el correlativo
 * completo en el detalle) pero NO afectan los totales ni el ISV a declarar.
 *
 * Este objeto es lo que el contador usa para llenar la declaración mensual
 * DEI (Formulario ISV-353) en el portal eSAR.
 */
final class SalesBookSummary
{
    public function __construct(
        public readonly int $periodYear,
        public readonly int $periodMonth,

        // Facturas (tipo 01)
        public readonly int $facturasEmitidasCount,
        public readonly int $facturasVigentesCount,
        public readonly int $facturasAnuladasCount,
        public readonly float $facturasExento,
        public readonly float $facturasGravado,
        public readonly float $facturasIsv,
        public readonly float $facturasTotal,

        // Notas de crédito (tipo 03)
        public readonly int $notasCreditoEmitidasCount,
        public readonly int $notasCreditoVigentesCount,
        public readonly int $notasCreditoAnuladasCount,
        public readonly float $notasCreditoExento,
        public readonly float $notasCreditoGravado,
        public readonly float $notasCreditoIsv,
        public readonly float $notasCreditoTotal,
    ) {}

    /**
     * Venta neta del período: facturas vigentes menos notas de crédito vigentes.
     *
     * Este es el monto que debe cuadrar con el subtotal de la declaración ISV
     * del período en el Formulario ISV-353.
     */
    public function ventaNeta(): float
    {
        return round($this->facturasTotal - $this->notasCreditoTotal, 2);
    }

    /**
     * Base gravada neta: gravado de facturas menos gravado de notas de crédito.
     */
    public function gravadoNeto(): float
    {
        return round($this->facturasGravado - $this->notasCreditoGravado, 2);
    }

    /**
     * Base exenta neta: exento de facturas menos exento de notas de crédito.
     */
    public function exentoNeto(): float
    {
        return round($this->facturasExento - $this->notasCreditoExento, 2);
    }

    /**
     * ISV neto a declarar: ISV de facturas menos ISV de notas de crédito.
     *
     * Este es el débito fiscal del período antes de cruzar con el crédito
     * fiscal del Libro de Compras.
     */
    public function isvNeto(): float
    {
        return round($this->facturasIsv - $this->notasCreditoIsv, 2);
    }

    /**
     * Etiqueta "Abril 2026" para títulos y nombres de archivo.
     */
    public function periodLabel(): string
    {
        $meses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];

        return ($meses[$this->periodMonth] ?? (string) $this->periodMonth) . ' ' . $this->periodYear;
    }

    /**
     * Sufijo "2026-04" para nombres de archivo.
     */
    public function periodSlug(): string
    {
        return sprintf('%04d-%02d', $this->periodYear, $this->periodMonth);
    }
}
