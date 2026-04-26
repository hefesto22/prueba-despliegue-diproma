<?php

namespace App\Enums;

/**
 * Tipos de retención ISV recibida por Diproma como sujeto pasivo.
 *
 * Cada caso mapea a una casilla concreta del Formulario 201 (ISV mensual) en
 * SIISAR — por eso mantenemos los tres unificados en una sola tabla con una
 * columna `retention_type` en vez de tener 3 modelos específicos.
 *
 * Origen legal:
 *   - tarjetas_credito_debito: Acuerdo 477-2013 (retención por procesamiento
 *     de pagos con tarjeta; el banco/procesador retiene 1.5% sobre el monto
 *     bruto de ventas con tarjeta y lo declara al SAR a nombre de Diproma).
 *   - ventas_estado: Decreto PCM-051-2011 + Art. 50 Ley ISV (el Estado o sus
 *     organismos descentralizados, al pagarle a un contribuyente, retienen el
 *     12.5% del ISV facturado; la retención se acredita en el 201 como crédito).
 *   - acuerdo_215_2010: Acuerdo 215-2010 SAR (grandes contribuyentes actúan
 *     como agentes de retención sobre sus proveedores; si Diproma le vende a
 *     un gran contribuyente, éste retiene parte del ISV al pagar).
 *
 * Tratamiento fiscal: los tres montos SUMAN al débito pagado/compensado del
 * período y se restan del ISV a pagar en la liquidación (sección D del 201).
 */
enum IsvRetentionType: string
{
    case TarjetasCreditoDebito = 'tarjetas_credito_debito';
    case VentasEstado = 'ventas_estado';
    case Acuerdo215_2010 = 'acuerdo_215_2010';

    /**
     * Etiqueta legible para UI (Filament forms, tablas, hojas de trabajo).
     */
    public function label(): string
    {
        return match ($this) {
            self::TarjetasCreditoDebito => 'Retención por tarjetas de crédito/débito',
            self::VentasEstado => 'Retención por ventas al Estado',
            self::Acuerdo215_2010 => 'Retención Acuerdo 215-2010 (gran contribuyente)',
        };
    }

    /**
     * Etiqueta corta para columnas de tabla y chips.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::TarjetasCreditoDebito => 'Tarjetas',
            self::VentasEstado => 'Estado',
            self::Acuerdo215_2010 => 'Ac. 215-2010',
        };
    }

    /**
     * Nombre de la casilla del Formulario 201 (ISV mensual SIISAR) donde se
     * declara este tipo de retención en la sección C "Créditos del período".
     *
     * Este texto aparece en la hoja de trabajo para que el contador sepa
     * dónde copiar cada monto al portal.
     */
    public function siisarCasilla(): string
    {
        return match ($this) {
            self::TarjetasCreditoDebito => 'C — Retenciones por tarjetas de crédito/débito',
            self::VentasEstado => 'C — Retenciones del Estado (Decreto PCM-051-2011)',
            self::Acuerdo215_2010 => 'C — Retenciones de agentes (Acuerdo 215-2010)',
        };
    }

    /**
     * ¿Requiere documento de constancia adjunto? (Todas lo requieren por
     * principio fiscal — sin constancia el SAR no reconoce la retención).
     */
    public function requiresDocument(): bool
    {
        return true;
    }

    /**
     * Opciones para Select de Filament: [value => label].
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type) => [$type->value => $type->label()])
            ->all();
    }
}
