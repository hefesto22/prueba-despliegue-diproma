<?php

namespace Database\Factories;

use App\Enums\TaxType;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Product;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditNoteItem>
 *
 * Factory de test — NO representa el flujo de emisión. El cálculo correcto
 * de subtotal/isv/total lo hace CreditNoteService a partir del sale_item
 * origen. Esta factory pone valores consistentes para que los tests puedan
 * verificar relaciones y estructura sin depender del servicio completo.
 */
class CreditNoteItemFactory extends Factory
{
    public function definition(): array
    {
        $quantity  = $this->faker->numberBetween(1, 5);
        $unitPrice = $this->faker->randomFloat(2, 50, 1000); // CON ISV
        $multiplier = (float) config('tax.multiplier', 1.15);

        $lineTotal = round($unitPrice * $quantity, 2);
        $subtotal  = round($lineTotal / $multiplier, 2);
        $isv       = round($lineTotal - $subtotal, 2);

        return [
            'credit_note_id' => CreditNote::factory(),
            'sale_item_id'   => SaleItem::factory(),
            'product_id'     => Product::factory(),

            'quantity'   => $quantity,
            'unit_price' => $unitPrice,
            'tax_type'   => TaxType::Gravado15,

            'subtotal'   => $subtotal,
            'isv_amount' => $isv,
            'total'      => $lineTotal,
        ];
    }

    public function exempt(): self
    {
        return $this->state(function (array $attrs) {
            $lineTotal = round((float) $attrs['unit_price'] * (int) $attrs['quantity'], 2);

            return [
                'tax_type'   => TaxType::Exento,
                'subtotal'   => $lineTotal,
                'isv_amount' => 0.0,
                'total'      => $lineTotal,
            ];
        });
    }
}
