<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Enums\SupplierDocumentType;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Supplier;
use App\Services\Purchases\InternalReceiptNumberGenerator;
use App\Services\Purchases\PurchaseTotalsCalculator;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreatePurchase extends CreateRecord
{
    protected static string $resource = PurchaseResource::class;

    /**
     * Servicios inyectados via boot() — Livewire 3 soporta method injection
     * en boot(). Propiedades protected para que NO se serialicen entre requests
     * (solo las public lo hacen) — el container resuelve fresh cada render.
     */
    protected InternalReceiptNumberGenerator $internalReceiptGenerator;

    protected PurchaseTotalsCalculator $totalsCalculator;

    public function boot(
        InternalReceiptNumberGenerator $internalReceiptGenerator,
        PurchaseTotalsCalculator $totalsCalculator,
    ): void {
        $this->internalReceiptGenerator = $internalReceiptGenerator;
        $this->totalsCalculator = $totalsCalculator;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Override del handle para envolver la creación de Purchase en una transacción.
     *
     * Razón crítica: cuando document_type=ReciboInterno, el número del RI se
     * genera con InternalReceiptNumberGenerator, que hace lockForUpdate sobre el
     * proveedor genérico. Ese lock debe estar vivo hasta el COMMIT del INSERT —
     * si generáramos el número fuera de la transacción del create, dos requests
     * concurrentes podrían recibir el mismo NNNN diario. Hacerlo acá garantiza:
     *   1) lock + generación + insert en la misma transacción → atomicidad.
     *   2) Si el insert falla, rollback completo — no queda número "quemado".
     *
     * Para document_type ≠ RI, el flujo pasa transparente (solo envuelve en
     * transacción lo que Filament haría igualmente).
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            if ($this->isReciboInterno($data['document_type'] ?? null)) {
                $data = $this->resolveReciboInternoFields($data);
            }

            return static::getModel()::create($data);
        });
    }

    /**
     * Completa los campos del Purchase cuando document_type=ReciboInterno:
     *   - supplier_id: respeta la elección del operador. Si dejó el campo vacío,
     *     cae al genérico "Varios / Sin identificar" como default operativo.
     *     Esta es una elección de UX legítima: el operador puede asociar un RI
     *     a un proveedor real para trazabilidad interna ("le compré a Comercial
     *     El Norte sin factura — quiero registrar a quién"). El RI NO entra al
     *     Libro de Compras SAR independientemente del proveedor — eso lo
     *     determina el filtro por document_type en PurchaseBookService, no
     *     por supplier_id, así que conservar el supplier real no afecta
     *     reportes fiscales ni crédito SAR.
     *   - supplier_invoice_number: correlativo RI-YYYYMMDD-NNNN generado atómicamente.
     *   - supplier_cai: null (RI no tiene CAI por definición).
     *   - credit_days: 0 (RI es siempre contado — no hay proveedor con crédito).
     *
     * La fecha del correlativo usa `date` del Purchase (fecha de la compra),
     * no now() — consistencia con la fecha que el usuario ingresó.
     *
     * Nota histórica: hasta 2026-04-25 el supplier_id se forzaba siempre al
     * genérico como "defense in depth" contra payloads manipulados. Esa regla
     * se relajó porque la trazabilidad interna del proveedor real es un
     * requerimiento de negocio legítimo y la separación con SAR vive en otra
     * capa (filtro por document_type en el Libro de Compras).
     */
    private function resolveReciboInternoFields(array $data): array
    {
        $fecha = isset($data['date'])
            ? Carbon::parse($data['date'])
            : Carbon::now();

        // Fallback al genérico solo si el operador no eligió proveedor.
        // empty() cubre null, '' y 0 — los tres casos posibles de "sin elección"
        // según cómo Filament/Livewire serialice el state del Select vacío.
        if (empty($data['supplier_id'])) {
            $data['supplier_id'] = Supplier::forInternalReceipts()->id;
        }

        $data['supplier_invoice_number'] = $this->internalReceiptGenerator->next($fecha);
        $data['supplier_cai'] = null;
        $data['credit_days'] = 0;

        return $data;
    }

    private function isReciboInterno(mixed $documentType): bool
    {
        return $documentType === SupplierDocumentType::ReciboInterno->value
            || $documentType === SupplierDocumentType::ReciboInterno;
    }

    /**
     * Al crear, recalcular totales (subtotal/taxable/exempt/isv/total)
     * desde los items. Delega al Calculator — fuente única de verdad.
     */
    protected function afterCreate(): void
    {
        $this->totalsCalculator->recalculate($this->record);
    }
}
