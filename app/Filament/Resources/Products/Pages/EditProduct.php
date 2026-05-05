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
     * 2. Mostrar el sale_price CON ISV (la BD lo guarda como base)
     *
     * El cost_price NO se convierte porque la BD lo guarda como costo
     * neto desde el inicio (ver CreateProduct::convertPricesToBase).
     * Mostrar el costo igual al ingresado evita la confusión que reporta
     * el usuario: "ingresé 1000 de costo y veo 869.57".
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Desempacar specs JSON a campos individuales del formulario
        $data = ProductForm::unpackSpecs($data);

        // Convertir SOLO el sale_price para display: la BD tiene base, el
        // form muestra precio público con ISV (más natural para el cajero).
        $isGravado = ($data['tax_type'] ?? '') === TaxType::Gravado15->value;

        if ($isGravado) {
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
