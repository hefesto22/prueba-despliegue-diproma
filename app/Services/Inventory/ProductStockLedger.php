<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\MovementType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\Establishments\EstablishmentResolver;

/**
 * Registra en el Kardex las entradas y salidas de stock que NACEN EN LA FICHA
 * DEL PRODUCTO — es decir, las que hace el operador a mano, no un documento.
 *
 * ─── Por qué este servicio existe (y por qué NO es un Observer) ─────────────
 *
 * Hasta ahora el Kardex solo se alimentaba de documentos: Compras, Ventas,
 * Notas de Crédito, Reparaciones y el form de Ajuste Manual. Crear un producto
 * con `stock = 5` desde el catálogo NO generaba ningún movimiento: el stock
 * aparecía de la nada y `stock_before` de la primera venta no tenía origen.
 *
 * Eso estaba enmascarado porque la entrada "real" de mercadería venía por
 * Compras confirmadas. Al convertir Compras en documento fiscal puro (sin
 * líneas de producto), la ficha del producto pasa a ser LA fuente de entrada
 * de inventario — y sin esta clase el Kardex quedaría con puras salidas.
 *
 * La tentación obvia era un `ProductObserver` sobre `saved`. Es una trampa:
 * hoy existen SIETE puntos que escriben `products.stock`
 * (PurchaseService, SaleInventoryProcessor, CreditNoteInventoryProcessor ×2,
 * RepairDeliveryService, CreateInventoryMovement, seeders) y TODOS ya registran
 * su propio InventoryMovement. Un observer global duplicaría cada uno de ellos:
 * el Kardex mostraría el doble de unidades y el inventario dejaría de cuadrar.
 *
 * Por eso la invocación es EXPLÍCITA y vive únicamente en las dos páginas de
 * Filament donde el operador toca el stock con la mano (CreateProduct y
 * EditProduct). Los Services pasan por `$product->update(['stock' => ...])`
 * directo y nunca cruzan este camino — imposible el doble conteo por
 * construcción, no por disciplina del desarrollador.
 *
 * ─── Convención de costos ───────────────────────────────────────────────────
 *
 * `unitCost` se captura como `cost_price`, que por convención del proyecto es
 * SIEMPRE el costo NETO sin ISV (ver docblock de Product). Esto mantiene el
 * Kardex homogéneo: entradas de compra, salidas de venta y estos ajustes se
 * pueden sumar entre sí sin mezclar unidades fiscales con unidades de costo.
 */
class ProductStockLedger
{
    public function __construct(
        private readonly EstablishmentResolver $establishments,
    ) {}

    /**
     * Carga inicial: el producto acaba de crearse con stock > 0.
     *
     * El movimiento arranca en `stock_before = 0` aunque el producto ya tenga
     * `stock = N` persistido. Sin ese override el kardex diría "de N pasó a 2N",
     * que es falso — la unidad no existía antes de este INSERT.
     *
     * @return InventoryMovement|null null si no aplica (servicio o stock 0).
     */
    public function recordInitialLoad(Product $product): ?InventoryMovement
    {
        if (! $this->tracksStock($product)) {
            return null;
        }

        $quantity = (int) $product->stock;

        // Producto creado en 0 (se cargará después por ajuste): no hay
        // movimiento que registrar. Un movimiento de cantidad 0 solo
        // ensuciaría el Kardex sin aportar información.
        if ($quantity <= 0) {
            return null;
        }

        return InventoryMovement::record(
            product: $product,
            type: MovementType::AjusteEntrada,
            quantity: $quantity,
            reference: null,
            notes: 'Carga inicial del producto',
            unitCost: (float) $product->cost_price,
            establishment: $this->establishments->resolve(),
            stockBefore: 0,
        );
    }

    /**
     * Ajuste manual: el operador cambió el campo "Cantidad en stock" en la
     * ficha del producto.
     *
     * Se llama DESPUÉS de persistir, así que `$product->stock` ya es el valor
     * nuevo y `$previousStock` lo trae el caller desde los atributos originales.
     * El signo del delta decide el tipo de movimiento.
     *
     * @param  int  $previousStock  Stock que tenía el producto ANTES del save.
     * @return InventoryMovement|null null si no aplica (servicio o sin cambio).
     */
    public function recordManualAdjustment(Product $product, int $previousStock): ?InventoryMovement
    {
        if (! $this->tracksStock($product)) {
            return null;
        }

        $newStock = (int) $product->stock;
        $delta = $newStock - $previousStock;

        // El operador editó el precio, la marca o los specs pero no tocó el
        // stock. Registrar un ajuste de 0 sería ruido puro en el Kardex.
        if ($delta === 0) {
            return null;
        }

        return InventoryMovement::record(
            product: $product,
            type: $delta > 0 ? MovementType::AjusteEntrada : MovementType::AjusteSalida,
            quantity: abs($delta),
            reference: null,
            notes: sprintf(
                'Ajuste manual desde la ficha del producto (%d → %d)',
                $previousStock,
                $newStock,
            ),
            unitCost: (float) $product->cost_price,
            establishment: $this->establishments->resolve(),
            stockBefore: $previousStock,
        );
    }

    /**
     * ¿Este producto lleva inventario real?
     *
     * Los servicios (Honorarios, mano de obra) se persisten con stock 999999
     * como infinito práctico para que SaleInventoryProcessor no lance
     * StockInsuficienteException. Ese número es un artefacto técnico, no
     * mercadería: meterlo al Kardex inflaría el valor del inventario en
     * millones y contaminaría cualquier reporte de existencias.
     */
    private function tracksStock(Product $product): bool
    {
        return ! $product->is_service;
    }
}
