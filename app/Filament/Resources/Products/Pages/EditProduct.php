<?php

namespace App\Filament\Resources\Products\Pages;

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

        // Convertir precios para display: usar tax_type como fuente de verdad
        // (no condition). condition solo aplica a productos físicos enum;
        // para custom (servicios o productos físicos custom) la regla fiscal
        // viene del tax_type explícito del producto.
        $isGravado = ($data['tax_type'] ?? '') === TaxType::Gravado15->value;

        if ($isGravado) {
            $data['cost_price'] = Product::priceWithIsv((float) ($data['cost_price'] ?? 0));
            $data['sale_price'] = Product::priceWithIsv((float) ($data['sale_price'] ?? 0));
        }

        return $data;
    }

    /**
     * Al guardar:
     * 1. Empacar campos spec_tipo_campo → specs JSON
     * 2. Convertir precios con ISV a precios base (solo si gravado)
     * 3. Stock infinito para tipos custom (servicios)
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = ProductForm::packSpecs($data);
        $data = CreateProduct::convertPricesToBase($data);
        $data = CreateProduct::applyServiceDefaults($data);

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
