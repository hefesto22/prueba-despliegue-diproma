<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\IsvMonthlyDeclaration;
use RuntimeException;

/**
 * Observer del modelo IsvMonthlyDeclaration — hace cumplir la inmutabilidad
 * de los campos fiscales post-insert y prohíbe el borrado de snapshots.
 *
 * Por qué existe:
 *   Este modelo es un registro histórico de lo que se presentó al SAR en un
 *   momento específico. La migración lo documenta como "foto exacta" del
 *   Formulario 201. Si los totales, el período o la fecha de presentación
 *   pudieran cambiar después del insert, el snapshot deja de ser un snapshot
 *   y se convierte en un buffer mutable — rompiendo la razón por la que
 *   existe la tabla (auditabilidad post-declaración).
 *
 *   La DB no hace cumplir esta regla (MySQL no tiene CHECK sobre UPDATE de
 *   columnas específicas de forma portable); la hace cumplir este Observer
 *   a nivel aplicación. Defense-in-depth: el Service nunca debería intentar
 *   esos cambios, pero si alguien llega por Tinker o un seeder mal diseñado,
 *   el Observer bloquea.
 *
 * Reglas cubiertas:
 *   - updating: solo permite mutación de columnas de ciclo de reemplazo
 *               (`superseded_at`, `superseded_by_user_id`), notas (`notes`)
 *               y auditoría (`updated_by`). Cualquier otra columna dirty
 *               lanza RuntimeException.
 *   - deleting / forceDeleting: bloqueo total. El concepto de "borrado" en
 *               este dominio es `superseded_at != null`, no DELETE.
 *
 * Por qué RuntimeException genérica (no una del dominio FiscalPeriods):
 *   Estos son errores de programación (someone trying to do the wrong thing
 *   from code), no errores de negocio que el usuario final deba manejar.
 *   No hay flujo legítimo donde un contador "intente" modificar un snapshot
 *   y tengamos que mostrarle un mensaje bonito — esos flujos pasan por el
 *   Service y crean un snapshot nuevo. Una RuntimeException cruda es
 *   apropiada: fail fast, log claro, bug visible en desarrollo.
 *
 * Columnas MUTABLES (whitelist explícita):
 *   - `notes`                → campo libre del contador (puede agregar nota tarde).
 *   - `superseded_at`        → lo setea el Service al crear una rectificativa.
 *   - `superseded_by_user_id`→ idem.
 *   - `updated_by`           → auditoría, lo setea HasAuditFields automáticamente.
 */
class IsvMonthlyDeclarationObserver
{
    /**
     * Columnas que SÍ pueden mutar después del insert. Cualquier otra columna
     * dirty en `updating` lanza excepción.
     *
     * No incluye `updated_at` porque Eloquent la setea automáticamente en
     * cualquier update — `isDirty()` la reporta como dirty pero no cuenta
     * como cambio intencional.
     */
    private const MUTABLE_COLUMNS = [
        'notes',
        'superseded_at',
        'superseded_by_user_id',
        'updated_by',
    ];

    public function updating(IsvMonthlyDeclaration $declaration): void
    {
        $dirty = array_keys($declaration->getDirty());

        // Eloquent añade `updated_at` como dirty en cualquier update — no es
        // un cambio intencional del caller, lo excluimos del chequeo.
        $dirty = array_values(array_filter(
            $dirty,
            fn (string $col) => $col !== 'updated_at',
        ));

        $forbidden = array_diff($dirty, self::MUTABLE_COLUMNS);

        if ($forbidden !== []) {
            $cols = implode(', ', $forbidden);

            throw new RuntimeException(
                "No se pueden modificar las columnas fiscales de una declaración ISV ya "
                . "registrada (ID #{$declaration->id}). Columnas bloqueadas: {$cols}. "
                . 'El snapshot es inmutable por diseño — para corregir, reabra el período '
                . 'fiscal y cree una rectificativa (el sistema marcará este registro como '
                . 'reemplazado automáticamente).'
            );
        }
    }

    public function deleting(IsvMonthlyDeclaration $declaration): void
    {
        throw new RuntimeException(
            "No se puede eliminar una declaración ISV (ID #{$declaration->id}). "
            . 'Los snapshots de declaraciones son permanentes por diseño fiscal — el '
            . 'concepto de "borrado" en este dominio es marcar el registro como '
            . 'reemplazado (superseded_at) vía una rectificativa.'
        );
    }
}
