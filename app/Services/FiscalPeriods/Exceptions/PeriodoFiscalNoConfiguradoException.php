<?php

namespace App\Services\FiscalPeriods\Exceptions;

/**
 * Se lanza cuando se intenta resolver el período fiscal de una operación
 * pero CompanySetting.fiscal_period_start no ha sido configurado. Es un
 * bloqueo intencional para evitar que Diproma emita o anule facturas sin
 * haber establecido desde qué fecha se controlan los períodos fiscales.
 *
 * Resolución: el administrador debe ir a Configuración de Empresa y
 * definir la "Fecha de inicio del tracking fiscal" (día 1 del mes).
 */
class PeriodoFiscalNoConfiguradoException extends FiscalPeriodException
{
    public function __construct()
    {
        parent::__construct(
            'No se puede operar con períodos fiscales: la empresa no ha configurado '
            . 'la fecha de inicio del tracking fiscal. Vaya a Configuración de Empresa '
            . 'y defina el campo "Inicio de período fiscal" antes de continuar.'
        );
    }
}
