<?php

namespace App\Filament\Resources\Products\Pages;

use App\Enums\TaxType;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\Product;
use App\Services\Inventory\ProductStockLedger;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Ver nota de serialización en CreateProduct::$stockLedger.
     */
    protected ProductStockLedger $stockLedger;

    public function boot(ProductStockLedger $stockLedger): void
    {
        $this->stockLedger = $stockLedger;
    }

    /**
     * Guardar el producto y, si el operador cambió el stock a mano, dejar el
     * ajuste correspondiente en el Kardex — todo en una transacción.
     *
     * ─── Por qué el stock previo se relee de la BD con lock ──────────────────
     *
     * El valor que el formulario cargó puede estar rancio: entre que el
     * operador abrió la ficha y le dio Guardar, una venta en el POS pudo bajar
     * el stock. Filament va a escribir igual el número del formulario, así que
     * si tomáramos el stock del form como punto de partida, el Kardex diría
     * "5 → 5, sin cambio" mientras la BD realmente pasó de 4 a 5 — una unidad
     * apareciendo de la nada, sin rastro.
     *
     * Releyendo bajo `lockForUpdate()` el asiento refleja lo que de verdad
     * ocurrió en la tabla, y el lock cierra la ventana entre la lectura y el
     * UPDATE para que ninguna venta se cuele en el medio.
     *
     * Los Services (ventas, compras, notas de crédito, reparaciones) NO pasan
     * por aquí — escriben `$product->update(['stock' => ...])` directo y
     * registran su propio movimiento. Por eso este camino no puede producir
     * doble conteo.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data) {
            $previousStock = (int) Product::query()
                ->whereKey($record->getKey())
                ->lockForUpdate()
                ->value('stock');

            /** @var Product $product */
            $product = parent::handleRecordUpdate($record, $data);

            $this->stockLedger->recordManualAdjustment($product, $previousStock);

            return $product;
        });
    }

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
