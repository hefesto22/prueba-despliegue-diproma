<?php

declare(strict_types=1);

namespace App\Services\Purchases;

use App\Enums\PurchaseStatus;
use App\Enums\SupplierDocumentType;
use App\Models\Purchase;

/**
 * Pre-carga datos del documento fiscal desde la última compra confirmada
 * del mismo proveedor.
 *
 * Motivación operativa:
 *   - El CAI de un proveedor suele ser el mismo durante meses (hasta que
 *     SAR le emita un nuevo rango). Reescribir 39 caracteres en cada
 *     compra es trabajo duplicado.
 *   - El prefijo del # factura (establecimiento-punto-tipo) casi nunca
 *     cambia para el mismo proveedor; solo cambia el correlativo.
 *
 * Comportamiento:
 *   - Si no hay compras previas confirmadas del proveedor → retorna null.
 *   - Solo considera documentos con CAI real (excluye RI y NC sin CAI),
 *     porque son los únicos que contienen datos útiles para prefill.
 *   - Expone la fecha de la compra fuente para que la UI avise al operador
 *     qué tan vieja es la referencia — útil para decidir si reverificar.
 *
 * Esta clase es pura (solo lectura), sin efectos secundarios. Segura de
 * llamar en `afterStateUpdated` del form sin bloquear la UI.
 */
class SupplierDocumentPrefill
{
    /**
     * @return array{
     *     cai: ?string,
     *     invoice_prefix: ?string,
     *     source_date: ?string
     * }|null
     */
    public function forSupplier(int $supplierId): ?array
    {
        $last = Purchase::query()
            ->where('supplier_id', $supplierId)
            ->where('status', PurchaseStatus::Confirmada)
            // Solo documentos SAR con CAI — RI no tiene CAI; NC puede no tenerlo propio.
            ->where('document_type', SupplierDocumentType::Factura)
            ->whereNotNull('supplier_cai')
            ->whereNotNull('supplier_invoice_number')
            ->orderByDesc('date')
            ->orderByDesc('id') // desempate estable cuando hay varias compras el mismo día
            ->select(['supplier_cai', 'supplier_invoice_number', 'date'])
            ->first();

        if ($last === null) {
            return null;
        }

        return [
            'cai' => $last->supplier_cai,
            // Prefijo establecimiento-punto-tipo (primeros 10 caracteres: XXX-XXX-XX),
            // +1 para incluir el guión separador antes del correlativo.
            // El correlativo queda vacío para que el operador solo escriba esos 8 dígitos.
            'invoice_prefix' => $this->extractInvoicePrefix($last->supplier_invoice_number),
            'source_date' => $last->date?->format('d/m/Y'),
        ];
    }

    /**
     * Extrae el prefijo "XXX-XXX-XX-" del número de factura SAR.
     * Si el formato no matchea lo esperado retorna null para no inducir errores.
     */
    private function extractInvoicePrefix(?string $invoiceNumber): ?string
    {
        if ($invoiceNumber === null) {
            return null;
        }

        // Formato esperado: 000-001-01-00000123 → prefijo "000-001-01-"
        if (preg_match('/^(\d{3}-\d{3}-\d{2}-)\d{8}$/', $invoiceNumber, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
