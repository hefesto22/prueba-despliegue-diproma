<?php

namespace App\Services\Invoicing\Resolvers;

use App\Enums\DocumentType;
use App\Models\CaiRange;
use App\Models\Establishment;
use App\Services\Invoicing\Contracts\ResuelveCorrelativoFactura;
use App\Services\Invoicing\DTOs\CorrelativoResuelto;
use App\Services\Invoicing\Exceptions\CaiVencidoException;
use App\Services\Invoicing\Exceptions\NoHayCaiActivoException;
use App\Services\Invoicing\Exceptions\RangoCaiAgotadoException;
use App\Services\Invoicing\Exceptions\TransaccionRequeridaException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sistema Computarizado Centralizado (Acuerdo 481-2017, 2.1).
 *
 * Una única numeración correlativa para toda la empresa, independiente del
 * establecimiento. El establishment_id del CAI se ignora al resolver;
 * cualquier CAI activo del tipo de documento sirve.
 *
 * Para el snapshot de la factura, establishment_id se resuelve a:
 *   1. El establishment_id del CAI si lo tiene.
 *   2. El establecimiento recibido como argumento si se pasó.
 *   3. El establecimiento matriz de la empresa (fallback).
 */
class CorrelativoCentralizado implements ResuelveCorrelativoFactura
{
    public function siguiente(
        DocumentType $documentType = DocumentType::Factura,
        ?int $establishmentId = null,
    ): CorrelativoResuelto {
        if (DB::transactionLevel() === 0) {
            throw new TransaccionRequeridaException();
        }

        // 1. CAI activo del tipo de documento (ignora establishment en modo centralizado)
        $caiRange = CaiRange::query()
            ->where('is_active', true)
            ->where('document_type', $documentType->value)
            ->lockForUpdate()
            ->first();

        if (! $caiRange) {
            throw new NoHayCaiActivoException($documentType->value);
        }

        // 2. Validar vencimiento
        if ($caiRange->expiration_date->isPast()) {
            throw new CaiVencidoException(
                caiRangeId: $caiRange->id,
                cai: $caiRange->cai,
                expirationDate: $caiRange->expiration_date,
            );
        }

        // 3. Validar rango disponible
        if ($caiRange->current_number >= $caiRange->range_end) {
            throw new RangoCaiAgotadoException(
                caiRangeId: $caiRange->id,
                cai: $caiRange->cai,
                rangeEnd: $caiRange->range_end,
            );
        }

        // 4. Avanzar correlativo (atómico dentro de la transacción + lock)
        $caiRange->increment('current_number');
        $nextNumber = $caiRange->current_number;
        $documentNumber = $caiRange->prefix . '-' . str_pad((string) $nextNumber, 8, '0', STR_PAD_LEFT);

        // 5. Resolver establishment para snapshot en el documento fiscal
        $resolvedEstablishment = $this->resolveEstablishment($caiRange, $establishmentId);

        return new CorrelativoResuelto(
            documentNumber: $documentNumber,
            cai: $caiRange->cai,
            caiRangeId: $caiRange->id,
            establishmentId: $resolvedEstablishment->id,
            emissionPoint: $resolvedEstablishment->emission_point,
            caiExpirationDate: CarbonImmutable::parse($caiRange->expiration_date),
        );
    }

    /**
     * Prioridad para snapshot:
     *   1. CAI tiene establishment_id vinculado → usar ese.
     *   2. Llamador pasó establishment_id → usar ese.
     *   3. Establecimiento matriz activo → fallback (query directa, sin depender del singleton).
     */
    private function resolveEstablishment(CaiRange $caiRange, ?int $fallbackEstablishmentId): Establishment
    {
        if ($caiRange->establishment_id) {
            $establishment = Establishment::find($caiRange->establishment_id);
            if ($establishment) {
                return $establishment;
            }
        }

        if ($fallbackEstablishmentId) {
            $establishment = Establishment::find($fallbackEstablishmentId);
            if ($establishment) {
                return $establishment;
            }
        }

        // Query directa al establecimiento matriz — no depende del singleton
        // CompanySetting::current() para evitar acoplar a su id hardcodeado.
        $main = Establishment::where('is_main', true)
            ->where('is_active', true)
            ->first();

        if (! $main) {
            throw new NoHayCaiActivoException(
                $caiRange->document_type,
                establishmentId: null,
            );
        }

        return $main;
    }
}
