<?php

namespace App\Services\FiscalBooks;

/**
 * Resumen del Libro de Compras SAR para un período mensual.
 *
 * Value object inmutable con los totales que el contador usa para cuadrar el
 * crédito fiscal del período en el Formulario ISV-353 (columna "Compras
 * gravadas" y "Crédito fiscal").
 *
 * Separa los 3 tipos de documento del SAR (Acuerdo 189-2014):
 *   - Factura (01):      suma al crédito fiscal
 *   - Nota de Crédito (03): RESTA al crédito fiscal (devolución / descuento)
 *   - Nota de Débito (04): suma al crédito fiscal (ajuste positivo)
 *
 * Las compras anuladas se cuentan por separado para trazabilidad pero NO
 * afectan los totales ni el crédito fiscal a declarar.
 *
 * Crédito fiscal neto = ISV(Factura) + ISV(ND) − ISV(NC), todas vigentes.
 */
final class PurchaseBookSummary
{
    public function __construct(
        public readonly int $periodYear,
        public readonly int $periodMonth,

        // Facturas recibidas (tipo 01)
        public readonly int $facturasEmitidasCount,
        public readonly int $facturasVigentesCount,
        public readonly int $facturasAnuladasCount,
        public readonly float $facturasExento,
        public readonly float $facturasGravado,
        public readonly float $facturasIsv,
        public readonly float $facturasTotal,

        // Notas de crédito recibidas (tipo 03)
        public readonly int $notasCreditoEmitidasCount,
        public readonly int $notasCreditoVigentesCount,
        public readonly int $notasCreditoAnuladasCount,
        public readonly float $notasCreditoExento,
        public readonly float $notasCreditoGravado,
        public readonly float $notasCreditoIsv,
        public readonly float $notasCreditoTotal,

        // Notas de débito recibidas (tipo 04)
        public readonly int $notasDebitoEmitidasCount,
        public readonly int $notasDebitoVigentesCount,
        public readonly int $notasDebitoAnuladasCount,
        public readonly float $notasDebitoExento,
        public readonly float $notasDebitoGravado,
        public readonly float $notasDebitoIsv,
        public readonly float $notasDebitoTotal,
    ) {}

    /**
     * Compra neta del período: facturas + notas de débito − notas de crédito.
     *
     * Este monto debe cuadrar con la base de "Compras del período" del
     * Formulario ISV-353.
     */
    public function compraNeta(): float
    {
        return round(
            $this->facturasTotal + $this->notasDebitoTotal - $this->notasCreditoTotal,
            2
        );
    }

    /**
     * Base gravada neta del período: gravado de facturas + ND − NC.
     *
     * Se usa para calcular el porcentaje de prorrateo cuando la empresa tiene
     * ventas gravadas y exentas (regla de proporcionalidad SAR).
     */
    public function gravadoNeto(): float
    {
        return round(
            $this->facturasGravado + $this->notasDebitoGravado - $this->notasCreditoGravado,
            2
        );
    }

    /**
     * Base exenta neta del período: exento de facturas + ND − NC.
     */
    public function exentoNeto(): float
    {
        return round(
            $this->facturasExento + $this->notasDebitoExento - $this->notasCreditoExento,
            2
        );
    }

    /**
     * Crédito fiscal neto del período: ISV de facturas + ISV de ND − ISV de NC.
     *
     * Es el monto del crédito fiscal que se cruza contra el débito fiscal
     * (`SalesBookSummary::isvNeto()`) en la declaración mensual ISV-353.
     *
     * Si el resultado es negativo significa que hubo más NC que facturas+ND
     * en el período — situación inusual pero válida (ej: mes con muchas
     * devoluciones y pocas compras nuevas).
     */
    public function creditoFiscalNeto(): float
    {
        return round(
            $this->facturasIsv + $this->notasDebitoIsv - $this->notasCreditoIsv,
            2
        );
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
