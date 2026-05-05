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
     * 2. Convertir SOLO el sale_price con ISV a base (no el costo)
     * 3. Stock infinito para servicios
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
     * Convertir el PRECIO DE VENTA con ISV a precio base para almacenar.
     *
     * Convención del catálogo:
     *   - cost_price: SIEMPRE se guarda como el costo NETO (lo que vale el
     *     producto en libros). NO se convierte. Si el producto se compró
     *     con factura, el ISV pagado al proveedor se registra por separado
     *     en la tabla `purchases` (genera crédito fiscal). El costo del
     *     producto en sí es siempre el neto.
     *   - sale_price: el usuario ingresa el precio CON ISV (forma comercial,
     *     lo que el cliente paga). Se almacena la base (sale_price/1.15)
     *     para que los cálculos fiscales en POS y reportes mensuales sean
     *     consistentes (subtotal + ISV = total).
     *
     * Para productos exentos (Usado, Honorarios) ningún precio se convierte
     * porque no hay ISV — se guardan tal cual.
     */
    public static function convertPricesToBase(array $data): array
    {
        $taxTypeValue = $data['tax_type'] ?? null;
        $isGravado = $taxTypeValue === TaxType::Gravado15->value;

        if ($isGravado) {
            // Solo el precio de venta se convierte: el form lo recibe CON ISV
            // (precio público) y la BD lo necesita como base.
            $data['sale_price'] = Product::priceWithoutIsv((float) ($data['sale_price'] ?? 0));
            // cost_price queda SIN tocar — es el costo neto en libros.
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
