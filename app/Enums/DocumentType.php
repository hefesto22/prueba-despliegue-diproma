<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Tipos de documento fiscal según normativa SAR Honduras
 * (Acuerdo 481-2017, Tabla de Códigos de Documento).
 *
 * Códigos oficiales:
 *   01 = Factura
 *   03 = Nota de Crédito
 *   04 = Nota de Débito
 *
 * Estos valores son el CONTRATO con SAR — nunca deben cambiarse sin una
 * reforma del acuerdo; son además el segmento que va en el prefijo fiscal
 * (p. ej. "001-001-03-00000001" para una nota de crédito).
 *
 * Es la única fuente de verdad en el código: el resolver, los formularios
 * y los servicios de emisión reciben este enum (type-safe) y delegan a
 * ->value cuando necesitan la representación string para persistencia.
 */
enum DocumentType: string implements HasLabel
{
    case Factura = '01';
    case NotaCredito = '03';
    case NotaDebito = '04';

    public function getLabel(): string
    {
        return match ($this) {
            self::Factura => 'Factura',
            self::NotaCredito => 'Nota de Crédito',
            self::NotaDebito => 'Nota de Débito',
        };
    }

    /**
     * Etiqueta con el código SAR entre paréntesis — pensado para los
     * Select de Filament donde el operador necesita ver el código exacto
     * que quedará guardado en el CAI.
     */
    public function getLabelWithCode(): string
    {
        return "{$this->getLabel()} ({$this->value})";
    }

    /**
     * Prefijo corto para IDs internos (no fiscal).
     */
    public function shortPrefix(): string
    {
        return match ($this) {
            self::Factura => 'FAC',
            self::NotaCredito => 'NC',
            self::NotaDebito => 'ND',
        };
    }
}
