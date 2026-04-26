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
use InvalidArgumentException;

/**
 * Sistema Computarizado por Sucursal (Acuerdo 481-2017, 2.1).
 *
 * Cada establecimiento maneja su propia numeración correlativa. Requiere
 * que cada CAI esté vinculado a un establishment_id específico y que el
 * llamador siempre pase el establishment_id al resolver.
 */
class CorrelativoPorSucursal implements ResuelveCorrelativoFactura
{
    public function siguiente(
        DocumentType $documentType = DocumentType::Factura,
        ?int $establishmentId = null,
    ): CorrelativoResuelto {
        if (DB::transactionLevel() === 0) {
            throw new TransaccionRequeridaException();
        }

        if (! $establishmentId) {
            throw new InvalidArgumentException(
                'En modo "por_sucursal" el establishment_id es obligatorio al resolver el correlativo. '
                . 'Verifique que el punto de venta o flujo de emisión esté enviando el establecimiento activo.'
            );
        }

        // 1. CAI activo del tipo de documento VINCULADO a este establishment
        $caiRange = CaiRange::query()
            ->where('is_active', true)
            ->where('document_type', $documentType->value)
            ->where('establishment_id', $establishmentId)
            ->lockForUpdate()
            ->first();

        if (! $caiRange) {
            throw new NoHayCaiActivoException($documentType->value, $establishmentId);
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

        // 4. Avanzar correlativo
        $caiRange->increment('current_number');
        $nextNumber = $caiRange->current_number;
        $documentNumber = $caiRange->prefix . '-' . str_pad((string) $nextNumber, 8, '0', STR_PAD_LEFT);

        // 5. Snapshot del establishment (el del CAI — garantizado no-null en este modo)
        $establishment = Establishment::findOrFail($caiRange->establishment_id);

        return new CorrelativoResuelto(
            documentNumber: $documentNumber,
            cai: $caiRange->cai,
            caiRangeId: $caiRange->id,
            establishmentId: $establishment->id,
            emissionPoint: $establishment->emission_point,
            caiExpirationDate: CarbonImmutable::parse($caiRange->expiration_date),
        );
    }
}
