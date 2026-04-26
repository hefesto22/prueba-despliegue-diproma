<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\TaxType;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Services\Purchases\PurchaseTotalsCalculator;

/**
 * Completa las cifras derivadas de un PurchaseItem en el momento del `creating`
 * cuando el caller no las provee explícitamente.
 *
 * Razón de existir: la tabla `purchase_items` declara `subtotal` y `total` como
 * columnas NOT NULL sin default. En producción el flujo típico (Filament
 * CreatePurchase → Repeater) no envía esas cifras porque confía en que el
 * `PurchaseTotalsCalculator` las persista en `afterCreate()`. Pero eso corre
 * DESPUÉS del INSERT — sin este observer el INSERT revienta con
 * "Field 'subtotal' doesn't have a default value".
 *
 * Estrategia fail-safe: cualquier path que cree items (Filament, API, jobs,
 * factories mal configuradas, seeders) obtiene cifras consistentes sin importar
 * si recuerda calcularlas. El `afterCreate()` del CreatePurchase sigue
 * recalculando para garantizar precisión absoluta — este observer solo evita
 * que la creación falle por datos incompletos.
 *
 * Respeta los valores que el caller SÍ haya enviado: si llega con `subtotal`
 * explícito (factories, tests) no lo sobrescribe.
 */
class PurchaseItemObserver
{
    public function creating(PurchaseItem $item): void
    {
        // Si ya vienen los tres campos calculados, no tocamos nada.
        if (
            $item->subtotal !== null
            && $item->isv_amount !== null
            && $item->total !== null
        ) {
            return;
        }

        // Sin quantity o unit_cost no hay forma de calcular — dejamos que la
        // restricción NOT NULL dispare un error claro, es mejor que guardar
        // ceros silenciosos que pasen inadvertidos en reportes fiscales.
        if ($item->quantity === null || $item->unit_cost === null) {
            return;
        }

        $taxType = $item->tax_type instanceof TaxType
            ? $item->tax_type
            : TaxType::tryFrom((string) $item->tax_type);

        // Resolvemos el document_type del Purchase padre para que el cálculo
        // preliminar respete la regla SupplierDocumentType::separatesIsv() —
        // RI no separa ISV aunque el producto sea Gravado15. Sin esto, el
        // observer haría back-out incorrecto en items de RI cuando algún
        // caller crea PurchaseItem sin llamar recalculate() después
        // (ej. seeders, scripts ad-hoc). El recalculate del Service sí
        // respeta la regla, pero confiar solo en eso deja una ventana de
        // estado inconsistente entre el INSERT del item y el afterCreate.
        //
        // El query extra (1 SELECT por purchase_id) es trivial — la columna
        // está indexada y en el flujo Filament típico ya viene cacheada.
        // Hidratamos el modelo (no usamos `value()`) para que el cast a
        // SupplierDocumentType del modelo Purchase se aplique automáticamente
        // — calculateLineFigures recibe el enum, no el string '99'.
        $documentType = $item->purchase_id
            ? Purchase::query()
                ->whereKey($item->purchase_id)
                ->first(['id', 'document_type'])
                ?->document_type
            : null;

        [$base, $isv, $total] = PurchaseTotalsCalculator::calculateLineFigures(
            unitCost: (float) $item->unit_cost,
            quantity: (int) $item->quantity,
            taxType: $taxType,
            documentType: $documentType,
        );

        $item->subtotal ??= $base;
        $item->isv_amount ??= $isv;
        $item->total ??= $total;
    }
}
