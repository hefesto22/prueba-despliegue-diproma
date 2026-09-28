<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchases\Pages\Concerns;

use App\Enums\SupplierDocumentType;
use App\Models\Supplier;
use App\Services\Purchases\Exceptions\MontosDocumentoInvalidosException;
use App\Services\Purchases\InternalReceiptNumberGenerator;
use App\Services\Purchases\PurchaseDocumentAmounts;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Completa y normaliza el payload del formulario de Compras antes de
 * persistirlo. Compartido por CreatePurchase y EditPurchase.
 *
 * Antes cada página tenía su propia copia de la resolución del Recibo Interno
 * y se desincronizaron: el 2026-04-25 Create empezó a respetar el proveedor
 * real elegido en un RI, pero Edit siguió forzando el genérico — editar
 * cualquier dato de un RI le borraba el proveedor. Una sola implementación
 * hace imposible esa divergencia.
 *
 * La página que use el trait debe asignar `$internalReceiptGenerator` en su
 * boot() (method injection de Livewire).
 */
trait ResolvesPurchaseDocument
{
    /**
     * Protected (no public) para que Livewire no lo serialice entre requests.
     */
    protected InternalReceiptNumberGenerator $internalReceiptGenerator;

    /**
     * Debe llamarse DENTRO de una transacción: el correlativo del RI toma un
     * lock que tiene que vivir hasta el COMMIT del INSERT/UPDATE.
     *
     * @param  array<string, mixed>  $data
     * @param  bool  $generateInternalReceiptNumber  Si un RI necesita correlativo nuevo
     *                                               (siempre al crear; al editar, solo si
     *                                               pasó a RI o cambió la fecha).
     * @return array<string, mixed>
     *
     * @throws ValidationException Si los montos violan una regla del documento.
     */
    protected function resolveDocumentFields(array $data, bool $generateInternalReceiptNumber): array
    {
        if (SupplierDocumentType::isReciboInterno($data['document_type'] ?? null)) {
            $data = $this->resolveReciboInternoFields($data, $generateInternalReceiptNumber);
        }

        return [...$data, ...$this->resolveAmounts($data)];
    }

    /**
     * Campos que el RI fija por definición:
     *   - supplier_id: se respeta el proveedor real que eligió el operador
     *     (trazabilidad interna); solo si quedó vacío cae al genérico
     *     "Varios / Sin identificar". El RI no entra al Libro de Compras por su
     *     document_type, no por su proveedor.
     *   - supplier_invoice_number: correlativo RI-YYYYMMDD-NNNN con la fecha
     *     del documento (no now()).
     *   - supplier_cai: null — un RI no tiene CAI.
     *   - credit_days: 0 — un RI es siempre contado.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveReciboInternoFields(array $data, bool $generateNumber): array
    {
        // empty() cubre null, '' y 0: las tres formas en que Livewire puede
        // serializar un Select vacío.
        if (empty($data['supplier_id'])) {
            $data['supplier_id'] = Supplier::forInternalReceipts()->id;
        }

        $data['supplier_cai'] = null;
        $data['credit_days'] = 0;

        if ($generateNumber) {
            $fecha = isset($data['date']) ? Carbon::parse($data['date']) : Carbon::now();
            $data['supplier_invoice_number'] = $this->internalReceiptGenerator->next($fecha);
        }

        return $data;
    }

    /**
     * Los montos se derivan SIEMPRE en el servidor (subtotal y total nunca
     * vienen del cliente). El formulario ya valida con las mismas reglas;
     * esto es la defensa si el payload llega manipulado.
     *
     * @param  array<string, mixed>  $data
     * @return array{subtotal: float, taxable_total: float, exempt_total: float, isv: float, total: float}
     */
    private function resolveAmounts(array $data): array
    {
        try {
            return PurchaseDocumentAmounts::fromFormData($data)->toAttributes();
        } catch (MontosDocumentoInvalidosException $e) {
            throw ValidationException::withMessages([
                "data.{$e->field}" => $e->getMessage(),
            ]);
        }
    }
}
