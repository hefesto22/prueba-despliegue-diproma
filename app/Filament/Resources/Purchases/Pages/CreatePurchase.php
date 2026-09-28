<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\Pages\Concerns\ResolvesPurchaseDocument;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Services\Purchases\InternalReceiptNumberGenerator;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreatePurchase extends CreateRecord
{
    use ResolvesPurchaseDocument;

    protected static string $resource = PurchaseResource::class;

    /**
     * Livewire 3 soporta method injection en boot(); el container resuelve
     * el servicio fresco en cada request.
     */
    public function boot(InternalReceiptNumberGenerator $internalReceiptGenerator): void
    {
        $this->internalReceiptGenerator = $internalReceiptGenerator;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Crear la compra dentro de una transacción.
     *
     * En Recibo Interno el correlativo se genera con lockForUpdate sobre el
     * proveedor genérico; ese lock debe vivir hasta el COMMIT del INSERT para
     * que dos requests concurrentes no reciban el mismo NNNN diario, y si el
     * INSERT falla el número no queda "quemado". Para Factura la transacción
     * es transparente.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(fn () => static::getModel()::create(
            $this->resolveDocumentFields($data, generateInternalReceiptNumber: true),
        ));
    }
}
