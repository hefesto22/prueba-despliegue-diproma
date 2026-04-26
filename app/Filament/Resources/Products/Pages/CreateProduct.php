<?php

namespace App\Filament\Resources\Products\Pages;

use App\Enums\ProductCondition;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\Product;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Transformar datos del formulario antes de crear el producto.
     * 1. Convertir campos spec_tipo_campo → specs JSON
     * 2. Convertir precios con ISV a precios base
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = ProductForm::packSpecs($data);
        $data = static::convertPricesToBase($data);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * Convertir precios con ISV a precios base para almacenar.
     */
    public static function convertPricesToBase(array $data): array
    {
        $isGravado = ($data['condition'] ?? '') !== ProductCondition::Used->value
            && ($data['condition'] ?? '') !== 'used';

        if ($isGravado) {
            $data['cost_price'] = Product::priceWithoutIsv((float) ($data['cost_price'] ?? 0));
            $data['sale_price'] = Product::priceWithoutIsv((float) ($data['sale_price'] ?? 0));
        }

        return $data;
    }
}
