<?php

namespace App\Concerns;

use App\Exceptions\Fiscal\DocumentoFiscalInmutableException;
use Illuminate\Database\Eloquent\Model;

/**
 * Bloquea la modificación de campos fiscales de un documento ya emitido.
 *
 * Aplica a Invoice, CreditNote y cualquier documento fiscal que siga el
 * patrón "emitted_at sella al documento". La regla es SAR: una vez sellado
 * el documento con su correlativo + CAI + integrity_hash, los únicos
 * cambios legítimos son estados operativos (anulación, asignación de ruta
 * del PDF generado async).
 *
 * Por qué trait y no Observer: la definición de "campo fiscal protegido"
 * es una sola regla transversal a todos los documentos fiscales. Un solo
 * punto de cambio si mañana SAR actualiza los campos snapshot exigidos.
 *
 * Cómo detecta "documento emitido":
 *  - Si `getOriginal('emitted_at')` es null  → documento aún no sellado,
 *    cualquier cambio es permitido (incluido sellar por primera vez).
 *  - Si `getOriginal('emitted_at')` NO es null → documento sellado, solo
 *    se permiten los campos declarados en `mutableFiscalFields()`.
 */
trait LocksFiscalFieldsAfterEmission
{
    /**
     * Whitelist de columnas mutables en un documento ya emitido.
     *
     * Los modelos pueden sobreescribir este método para ampliar la lista
     * con campos operativos adicionales específicos de su tipo. NUNCA
     * incluir aquí totales, numeración, CAI ni snapshots.
     *
     * @return string[]
     */
    protected function mutableFiscalFields(): array
    {
        return ['is_void', 'pdf_path', 'updated_at'];
    }

    protected static function bootLocksFiscalFieldsAfterEmission(): void
    {
        static::updating(function (Model $model) {
            // Usamos getOriginal() (valor desde DB), no el atributo en memoria:
            // al sellar por primera vez (emitted_at: null -> now()) esta misma
            // llamada a save() dispara updating() y NO debe bloquearse.
            $wasSealed = $model->getOriginal('emitted_at') !== null;

            if (! $wasSealed) {
                return;
            }

            /** @var string[] $dirty */
            $dirty   = array_keys($model->getDirty());
            /** @var string[] $allowed */
            $allowed = $model->mutableFiscalFields();
            /** @var string[] $blocked */
            $blocked = array_values(array_diff($dirty, $allowed));

            if ($blocked === []) {
                return;
            }

            throw new DocumentoFiscalInmutableException(
                documentType: class_basename($model),
                documentId:   $model->getKey(),
                dirtyFields:  $blocked,
            );
        });
    }
}
