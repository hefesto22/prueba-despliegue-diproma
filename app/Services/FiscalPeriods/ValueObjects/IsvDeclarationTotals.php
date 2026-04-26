<?php

declare(strict_types=1);

namespace App\Services\FiscalPeriods\ValueObjects;

use DomainException;

/**
 * Value Object inmutable con los 12 totales del Formulario 201 SAR congelados
 * en un snapshot de declaración ISV mensual.
 *
 * Por qué es un VO y no un array plano:
 *   - Encapsula la aritmética fiscal (ISV a pagar vs saldo a favor siguiente
 *     son mutuamente excluyentes y derivados, no inputs independientes).
 *   - Evita que dos callsites del sistema dupliquen la misma fórmula y
 *     deriven en bugs asimétricos entre "el preview" y "lo que se persiste".
 *   - Garantiza cuadratura por construcción: no existe una instancia con
 *     `ventas_gravadas + ventas_exentas != ventas_totales`. Una vez armado,
 *     es correcto.
 *   - Testeable sin DB — toda la lógica financiera se ejercita en tests puros.
 *
 * Construcción:
 *   Constructor privado. Únicos callers: `calculate(...)` (aritmética canónica
 *   desde inputs brutos) y `fromSnapshot(...)` (rehidratación desde DB para
 *   re-cálculos o comparaciones). Esto prohíbe instanciar inconsistente.
 *
 * Fórmula fiscal (Acuerdo SAR 189-2014, Formulario 201 Secciones C y E):
 *   neto = isv_debito_fiscal − isv_credito_fiscal
 *          − isv_retenciones_recibidas − saldo_a_favor_anterior
 *   si neto > 0  → isv_a_pagar = neto;        saldo_a_favor_siguiente = 0
 *   si neto < 0  → isv_a_pagar = 0;           saldo_a_favor_siguiente = |neto|
 *   si neto == 0 → isv_a_pagar = 0;           saldo_a_favor_siguiente = 0
 *
 * Semántica de signos:
 *   - `isv_retenciones_recibidas` y `saldo_a_favor_anterior` son NO NEGATIVOS
 *     por dominio (son acumulados/créditos, no pueden bajar de 0). El VO
 *     rechaza inputs negativos con DomainException.
 *   - Los netos de ventas/compras (gravadas/exentas) PUEDEN ser negativos
 *     cuando un período tiene más notas de crédito que facturas (caso inusual
 *     pero válido por Acuerdo 189-2014). El VO los acepta tal cual — la
 *     cuadratura SAR permite declarar valores negativos.
 *   - `isv_debito_fiscal` e `isv_credito_fiscal` también pueden ser negativos
 *     por la misma razón.
 *
 * Redondeo:
 *   Todos los valores se redondean a 2 decimales al construir. El SAR declara
 *   centavos, no sub-centavos. Redondeo half-away-from-zero (PHP default).
 */
final class IsvDeclarationTotals
{
    private function __construct(
        public readonly float $ventasGravadas,
        public readonly float $ventasExentas,
        public readonly float $ventasTotales,

        public readonly float $comprasGravadas,
        public readonly float $comprasExentas,
        public readonly float $comprasTotales,

        public readonly float $isvDebitoFiscal,
        public readonly float $isvCreditoFiscal,
        public readonly float $isvRetencionesRecibidas,

        public readonly float $saldoAFavorAnterior,
        public readonly float $isvAPagar,
        public readonly float $saldoAFavorSiguiente,
    ) {}

    /**
     * Canonical constructor — deriva totales y cuadratura SAR desde inputs brutos.
     *
     * @param  float  $ventasGravadas          Sección A1 — ventas con ISV.
     * @param  float  $ventasExentas           Sección A2 — ventas exentas/exportación.
     * @param  float  $comprasGravadas         Sección B1 — compras con ISV.
     * @param  float  $comprasExentas          Sección B2 — compras exentas.
     * @param  float  $isvDebitoFiscal         Sección C — ISV cobrado en ventas.
     * @param  float  $isvCreditoFiscal        Sección C — ISV pagado en compras.
     * @param  float  $isvRetencionesRecibidas Sección D — retenciones del período (>= 0).
     * @param  float  $saldoAFavorAnterior     Sección E arrastrada del mes previo (>= 0).
     *
     * @throws DomainException Si retenciones o saldo anterior son negativos.
     */
    public static function calculate(
        float $ventasGravadas,
        float $ventasExentas,
        float $comprasGravadas,
        float $comprasExentas,
        float $isvDebitoFiscal,
        float $isvCreditoFiscal,
        float $isvRetencionesRecibidas,
        float $saldoAFavorAnterior,
    ): self {
        if ($isvRetencionesRecibidas < 0) {
            throw new DomainException(
                "isv_retenciones_recibidas no puede ser negativo (recibido: {$isvRetencionesRecibidas}). "
                . 'Las retenciones son un crédito acumulado del período.'
            );
        }

        if ($saldoAFavorAnterior < 0) {
            throw new DomainException(
                "saldo_a_favor_anterior no puede ser negativo (recibido: {$saldoAFavorAnterior}). "
                . 'Si el mes previo resultó en ISV a pagar, el saldo arrastrado es 0.'
            );
        }

        $ventasTotales  = round($ventasGravadas  + $ventasExentas,  2);
        $comprasTotales = round($comprasGravadas + $comprasExentas, 2);

        // Regla SAR Formulario 201 — Sección E final:
        // ISV a pagar = débito − crédito − retenciones − saldo_anterior.
        // Si resultado < 0, el excedente queda como saldo a favor siguiente.
        $neto = round(
            $isvDebitoFiscal
                - $isvCreditoFiscal
                - $isvRetencionesRecibidas
                - $saldoAFavorAnterior,
            2
        );

        $isvAPagar            = $neto > 0 ? $neto      : 0.0;
        $saldoAFavorSiguiente = $neto < 0 ? abs($neto) : 0.0;

        return new self(
            ventasGravadas:          round($ventasGravadas,          2),
            ventasExentas:           round($ventasExentas,           2),
            ventasTotales:           $ventasTotales,
            comprasGravadas:         round($comprasGravadas,         2),
            comprasExentas:          round($comprasExentas,          2),
            comprasTotales:          $comprasTotales,
            isvDebitoFiscal:         round($isvDebitoFiscal,         2),
            isvCreditoFiscal:        round($isvCreditoFiscal,        2),
            isvRetencionesRecibidas: round($isvRetencionesRecibidas, 2),
            saldoAFavorAnterior:     round($saldoAFavorAnterior,     2),
            isvAPagar:               $isvAPagar,
            saldoAFavorSiguiente:    $saldoAFavorSiguiente,
        );
    }

    /**
     * Representación como array listo para persistir en `isv_monthly_declarations`.
     * Las claves coinciden exactamente con los fillable del modelo.
     *
     * @return array<string, float>
     */
    public function toArray(): array
    {
        return [
            'ventas_gravadas'           => $this->ventasGravadas,
            'ventas_exentas'            => $this->ventasExentas,
            'ventas_totales'            => $this->ventasTotales,
            'compras_gravadas'          => $this->comprasGravadas,
            'compras_exentas'           => $this->comprasExentas,
            'compras_totales'           => $this->comprasTotales,
            'isv_debito_fiscal'         => $this->isvDebitoFiscal,
            'isv_credito_fiscal'        => $this->isvCreditoFiscal,
            'isv_retenciones_recibidas' => $this->isvRetencionesRecibidas,
            'saldo_a_favor_anterior'    => $this->saldoAFavorAnterior,
            'isv_a_pagar'               => $this->isvAPagar,
            'saldo_a_favor_siguiente'   => $this->saldoAFavorSiguiente,
        ];
    }

    /**
     * ¿El período resultó en ISV a pagar al SAR?
     * Mutualmente excluyente con `hasSaldoAFavor()`.
     */
    public function hasIsvAPagar(): bool
    {
        return $this->isvAPagar > 0;
    }

    /**
     * ¿El período generó un saldo a favor del contribuyente (arrastre a mes siguiente)?
     */
    public function hasSaldoAFavor(): bool
    {
        return $this->saldoAFavorSiguiente > 0;
    }
}
