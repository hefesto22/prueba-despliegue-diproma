<?php

namespace App\Filament\Resources\InventoryMovements\Pages;

use App\Enums\MovementType;
use App\Filament\Resources\InventoryMovements\InventoryMovementResource;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\Establishments\EstablishmentResolver;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateInventoryMovement extends CreateRecord
{
    protected static string $resource = InventoryMovementResource::class;

    protected static ?string $title = 'Ajuste Manual de Inventario';

    /**
     * EstablishmentResolver inyectado via boot() — Livewire 3 soporta method
     * injection. Propiedad protected para que NO se serialice entre requests.
     */
    protected EstablishmentResolver $establishments;

    public function boot(EstablishmentResolver $establishments): void
    {
        $this->establishments = $establishments;
    }

    /**
     * Interceptar la creación para:
     * 1. Registrar stock_before / stock_after correctos
     * 2. Actualizar el stock del producto (con lock)
     * 3. Todo dentro de una transacción
     */
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        // Resolver la sucursal activa ANTES de abrir la transacción —
        // si falta configuración, el user recibe el error sin side-effects.
        // Se propaga como NoActiveEstablishmentException; Filament la muestra
        // al user con el mensaje de la excepción.
        $establishment = $this->establishments->resolve();

        return DB::transaction(function () use ($data, $establishment) {
            $product = Product::where('id', $data['product_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $type = MovementType::from($data['type']);
            $quantity = (int) $data['quantity'];

            // Validar que no quede stock negativo en salida
            if ($type->isExit() && $product->stock < $quantity) {
                throw new \RuntimeException(
                    "Stock insuficiente. Stock actual: {$product->stock}, cantidad solicitada: {$quantity}."
                );
            }

            // Registrar el movimiento (calcula stock_before y stock_after)
            // unit_cost: costo promedio actual del producto — representa la
            // valorización del stock ajustado al momento del ajuste.
            $movement = InventoryMovement::record(
                product: $product,
                type: $type,
                quantity: $quantity,
                reference: null,
                notes: $data['notes'] ?? null,
                unitCost: (float) $product->cost_price,
                establishment: $establishment,
            );

            // Actualizar stock del producto
            $newStock = $type->isEntry()
                ? $product->stock + $quantity
                : max(0, $product->stock - $quantity);

            $product->update(['stock' => $newStock]);

            return $movement;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
