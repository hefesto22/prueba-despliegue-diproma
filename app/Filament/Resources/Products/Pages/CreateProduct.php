<?php

namespace App\Filament\Resources\Products\Pages;

use App\Enums\ProductCondition;
use App\Enums\TaxType;
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
     * 2. Convertir precios con ISV a precios base (solo si gravado)
     * 3. Stock infinito para tipos custom (servicios sin inventario)
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = ProductForm::packSpecs($data);
        $data = static::convertPricesToBase($data);
        $data = static::applyServiceDefaults($data);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * Convertir precios con ISV a precios base para almacenar.
     *
     * Solo aplica si el producto es gravado. Para servicios exentos
     * (Honorarios) o productos usados, el precio se almacena tal cual
     * lo ingresó el usuario.
     */
    public static function convertPricesToBase(array $data): array
    {
        $taxTypeValue = $data['tax_type'] ?? null;
        $isGravado = $taxTypeValue === TaxType::Gravado15->value;

        if ($isGravado) {
            $data['cost_price'] = Product::priceWithoutIsv((float) ($data['cost_price'] ?? 0));
            $data['sale_price'] = Product::priceWithoutIsv((float) ($data['sale_price'] ?? 0));
        }

        return $data;
    }

    /**
     * Para SERVICIOS (is_service=true) inyectar defaults técnicos:
     *   - stock = 999999 (infinito en la práctica). Esto evita que el
     *     SaleInventoryProcessor lance StockInsuficienteException al
     *     vender un servicio como Honorarios.
     *   - min_stock = 0 (no genera alerta de stock bajo).
     *   - condition = New (placeholder — la columna es NOT NULL pero
     *     conceptualmente no aplica a servicios).
     *
     * Para productos físicos (is_service=false), incluso si son tipos
     * custom como "Equipo de seguridad", NO se aplican estos defaults —
     * se respeta el stock ingresado en el form, la condición elegida, etc.
     * Eso permite que el inventario funcione normalmente para productos
     * físicos no-enum.
     */
    public static function applyServiceDefaults(array $data): array
    {
        $isService = (bool) ($data['is_service'] ?? false);

        if (! $isService) {
            return $data; // producto físico: respetar lo que viene del form
        }

        $data['stock'] = 999999;
        $data['min_stock'] = 0;
        $data['condition'] = ProductCondition::New->value;

        return $data;
    }
}
