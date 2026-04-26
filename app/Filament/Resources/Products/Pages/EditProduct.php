<?php

namespace App\Filament\Resources\Products\Pages;

use App\Enums\ProductCondition;
use App\Enums\TaxType;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\Product;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Al cargar el formulario:
     * 1. Desempacar specs JSON → campos spec_tipo_campo
     * 2. Convertir precios base a precios con ISV (para gravados)
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Desempacar specs JSON a campos individuales del formulario
        $data = ProductForm::unpackSpecs($data);

        // Convertir precios para display
        $isGravado = ($data['condition'] ?? '') !== ProductCondition::Used->value
            && ($data['condition'] ?? '') !== 'used';

        if ($isGravado) {
            $data['cost_price'] = Product::priceWithIsv((float) ($data['cost_price'] ?? 0));
            $data['sale_price'] = Product::priceWithIsv((float) ($data['sale_price'] ?? 0));
        }

        return $data;
    }

    /**
     * Al guardar:
     * 1. Empacar campos spec_tipo_campo → specs JSON
     * 2. Convertir precios con ISV a precios base
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = ProductForm::packSpecs($data);
        $data = CreateProduct::convertPricesToBase($data);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
