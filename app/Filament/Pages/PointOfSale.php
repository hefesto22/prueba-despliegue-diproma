<?php

namespace App\Filament\Pages;

use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Enums\TaxType;
use App\Models\Product;
use App\Services\Cash\Exceptions\NoHayCajaAbiertaException;
use App\Services\Establishments\EstablishmentResolver;
use App\Services\Establishments\Exceptions\NoActiveEstablishmentException;
use App\Services\Invoicing\InvoiceService;
use App\Services\Sales\SaleService;
use App\Services\Sales\Tax\SaleTaxCalculator;
use App\Services\Sales\Tax\TaxableLine;
use BackedEnum;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Computed;

class PointOfSale extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $navigationLabel = 'Punto de Venta';

    protected static ?string $title = 'Punto de Venta';

    protected static ?string $slug = 'pos';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.point-of-sale';

    public static function getNavigationGroup(): ?string
    {
        return 'Ventas';
    }

    // ─── Estado del carrito (Livewire) ──────────────────────

    /** @var array<int, array{product_id: int, name: string, sku: string, unit_price: float, tax_type: string, quantity: int, stock: int}> */
    public array $cart = [];

    public string $customerName = '';
    public string $customerRtn = '';
    public string $discountType = '';
    public string $discountValue = '';
    public string $notes = '';
    public bool $withoutCai = false;

    /**
     * Método de pago de la venta. El enum value (string) se enlaza vía
     * Livewire al radio group del modal. Default `efectivo` porque es el
     * caso mayoritario en retail de mostrador. Se convierte a
     * `PaymentMethod` al enviar a `SaleService::processSale()`.
     */
    public string $paymentMethod = 'efectivo';

    // ─── DI via boot() ──────────────────────────────────────
    // Servicios inyectados en cada request — Livewire 3 soporta method
    // injection en boot(). Propiedades protected para que NO se serialicen
    // entre requests (solo las public lo hacen) — el container resuelve
    // fresh cada render y mantiene los singletons registrados.

    protected SaleService $sales;

    protected InvoiceService $invoices;

    protected EstablishmentResolver $establishments;

    /**
     * E.2.M1 — Calculator inyectado para que el preview fiscal del POS use la
     * misma lógica que SaleService persiste. Singleton: una instancia por
     * request, multiplier resuelto una sola vez desde config('tax.multiplier').
     */
    protected SaleTaxCalculator $taxCalculator;

    public function boot(
        SaleService $sales,
        InvoiceService $invoices,
        EstablishmentResolver $establishments,
        SaleTaxCalculator $taxCalculator,
    ): void {
        $this->sales = $sales;
        $this->invoices = $invoices;
        $this->establishments = $establishments;
        $this->taxCalculator = $taxCalculator;
    }

    // ─── Tabla de Productos ─────────────────────────────────

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Product::query()
                    ->where('is_active', true)
                    ->where('stock', '>', 0)
            )
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable()
                    ->size('sm')
                    ->color('gray')
                    ->copyable(),

                TextColumn::make('name')
                    ->label('Producto')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->limit(60),

                TextColumn::make('brand')
                    ->label('Marca')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('sale_price')
                    ->label('Precio')
                    ->money('HNL')
                    ->sortable(),

                TextColumn::make('stock')
                    ->label('Disponible')
                    ->sortable()
                    ->alignCenter()
                    ->badge()
                    ->getStateUsing(fn (Product $record): int => $this->getAvailableStock($record->id))
                    ->color(fn (Product $record): string => match (true) {
                        $this->getAvailableStock($record->id) <= 0 => 'gray',
                        $this->getAvailableStock($record->id) <= 5 => 'danger',
                        $this->getAvailableStock($record->id) <= $record->min_stock => 'warning',
                        default => 'success',
                    }),

                TextColumn::make('tax_type')
                    ->label('ISV')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Action::make('addToCart')
                    ->label(fn (Product $record): string => $this->getCartQuantity($record->id) > 0
                        ? 'En carrito (' . $this->getCartQuantity($record->id) . ')'
                        : 'Agregar'
                    )
                    ->icon('heroicon-o-plus-circle')
                    ->color(fn (Product $record): string => $this->getAvailableStock($record->id) <= 0
                        ? 'gray'
                        : 'primary'
                    )
                    ->size('sm')
                    ->disabled(fn (Product $record): bool => $this->getAvailableStock($record->id) <= 0)
                    ->action(fn (Product $record) => $this->addToCart($record->id)),
            ])
            ->searchPlaceholder('Buscar por nombre, SKU o marca...')
            ->defaultSort('name')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->striped()
            ->deferLoading();
    }

    // ─── Computed Properties ─────────────────────────────────

    /**
     * Total bruto del carrito (antes de descuento).
     */
    #[Computed]
    public function cartGrossTotal(): float
    {
        return collect($this->cart)->sum(fn ($item) => $item['unit_price'] * $item['quantity']);
    }

    /**
     * Monto del descuento calculado.
     */
    #[Computed]
    public function discountAmount(): float
    {
        if (blank($this->discountType) || blank($this->discountValue) || (float) $this->discountValue <= 0) {
            return 0;
        }

        $type = DiscountType::tryFrom($this->discountType);
        if (! $type) {
            return 0;
        }

        return $type->calculateAmount((float) $this->discountValue, $this->cartGrossTotal);
    }

    /**
     * Total neto (después de descuento).
     */
    #[Computed]
    public function cartNetTotal(): float
    {
        return max(0, $this->cartGrossTotal - $this->discountAmount);
    }

    /**
     * Desglose fiscal del total neto con descuento proporcional.
     *
     * E.2.M1 — Delega a SaleTaxCalculator. La lógica fiscal vive ahora en una
     * sola clase compartida con SaleService::calculateTotals(), garantizando
     * que el preview que ve el cajero coincida exactamente con lo que se
     * persiste al procesar la venta (subtotal/ISV/total al centavo).
     */
    #[Computed]
    public function taxBreakdown(): array
    {
        if (empty($this->cart)) {
            return ['subtotal' => 0, 'isv' => 0, 'total' => 0];
        }

        // Mapear cart[] → TaxableLine[]. No pasamos identity: en preview no
        // hay IDs de items (los SaleItem aún no existen) y no necesitamos
        // mapear líneas de vuelta — solo mostramos totales agregados.
        $lines = array_map(
            fn (array $item): TaxableLine => new TaxableLine(
                unitPrice: (float) $item['unit_price'],
                quantity: (int) $item['quantity'],
                taxType: TaxType::from($item['tax_type']),
            ),
            $this->cart,
        );

        $breakdown = $this->taxCalculator->calculate($lines, (float) $this->discountAmount);

        return [
            'subtotal' => $breakdown->subtotal,
            'isv' => $breakdown->isv,
            'total' => $breakdown->total,
        ];
    }

    /**
     * Cantidad total de items en el carrito.
     */
    #[Computed]
    public function cartItemCount(): int
    {
        return collect($this->cart)->sum('quantity');
    }

    // ─── Helpers de stock vs carrito ────────────────────────

    /**
     * Cantidad de un producto que ya está en el carrito.
     */
    public function getCartQuantity(int $productId): int
    {
        $item = collect($this->cart)->firstWhere('product_id', $productId);

        return $item ? $item['quantity'] : 0;
    }

    /**
     * Stock disponible = stock real − lo que ya está en el carrito.
     */
    public function getAvailableStock(int $productId): int
    {
        $product = collect($this->cart)->firstWhere('product_id', $productId);
        $inCart = $product ? $product['quantity'] : 0;

        // Obtener stock real desde el registro (o DB si no está cacheado)
        $dbStock = Product::where('id', $productId)->value('stock') ?? 0;

        return max(0, $dbStock - $inCart);
    }

    // ─── Acciones del carrito ────────────────────────────────

    /**
     * Agregar producto al carrito.
     */
    public function addToCart(int $productId): void
    {
        $product = Product::find($productId);

        if (! $product || $product->stock <= 0) {
            Notification::make()
                ->title('Producto sin stock')
                ->danger()
                ->send();
            return;
        }

        // Buscar si ya está en el carrito
        $index = collect($this->cart)->search(fn ($item) => $item['product_id'] === $productId);

        if ($index !== false) {
            $currentQty = $this->cart[$index]['quantity'];

            if ($currentQty >= $product->stock) {
                Notification::make()
                    ->title('Máximo alcanzado')
                    ->body("Ya tienes {$currentQty} de {$product->stock} disponibles en tu carrito.")
                    ->warning()
                    ->send();
                return;
            }

            $this->cart[$index]['quantity']++;
            $newQty = $this->cart[$index]['quantity'];

            Notification::make()
                ->title($product->name)
                ->body("Cantidad: {$newQty} de {$product->stock}")
                ->success()
                ->duration(1500)
                ->send();
        } else {
            $this->cart[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'unit_price' => (float) $product->sale_price,
                'tax_type' => $product->tax_type->value,
                'quantity' => 1,
                'stock' => $product->stock,
            ];

            Notification::make()
                ->title($product->name)
                ->body("Agregado al carrito (1 de {$product->stock})")
                ->success()
                ->duration(1500)
                ->send();
        }
    }

    /**
     * Modificar cantidad de un item.
     */
    public function updateQuantity(int $index, int $quantity): void
    {
        if (! isset($this->cart[$index])) {
            return;
        }

        if ($quantity <= 0) {
            $this->removeFromCart($index);
            return;
        }

        if ($quantity > $this->cart[$index]['stock']) {
            Notification::make()
                ->title('Stock insuficiente')
                ->body("Solo hay {$this->cart[$index]['stock']} unidades disponibles.")
                ->warning()
                ->send();
            return;
        }

        $this->cart[$index]['quantity'] = $quantity;
    }

    /**
     * Eliminar item del carrito.
     */
    public function removeFromCart(int $index): void
    {
        unset($this->cart[$index]);
        $this->cart = array_values($this->cart);
    }

    /**
     * Vaciar carrito y resetear formulario.
     */
    public function clearCart(): void
    {
        $this->cart = [];
        $this->customerName = '';
        $this->customerRtn = '';
        $this->discountType = '';
        $this->discountValue = '';
        $this->notes = '';
        $this->withoutCai = false;
        $this->paymentMethod = 'efectivo';

        $this->dispatch('close-modal', id: 'carrito-modal');
    }

    /**
     * Procesar la venta completa y generar factura.
     */
    public function processSale(): void
    {
        if (empty($this->cart)) {
            Notification::make()
                ->title('Carrito vacío')
                ->body('Agrega productos antes de procesar la venta.')
                ->danger()
                ->send();
            return;
        }

        try {
            // Resolver la sucursal activa del cajero ANTES de abrir transacciones.
            // Falla temprano con mensaje claro si el user no tiene sucursal asignada.
            $establishment = $this->establishments->resolve();

            // 1. Procesar la venta
            $sale = $this->sales->processSale(
                cartItems: collect($this->cart)->map(fn ($item) => [
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_type' => $item['tax_type'],
                ])->toArray(),
                paymentMethod: PaymentMethod::from($this->paymentMethod),
                customerName: filled($this->customerName) ? $this->customerName : 'Consumidor Final',
                customerRtn: filled($this->customerRtn) ? $this->customerRtn : null,
                discountType: filled($this->discountType) ? DiscountType::tryFrom($this->discountType) : null,
                discountValue: filled($this->discountValue) ? (float) $this->discountValue : null,
                notes: filled($this->notes) ? $this->notes : null,
                establishment: $establishment,
            );

            // 2. Generar factura fiscal
            $invoice = $this->invoices->generateFromSale($sale, $this->withoutCai);

            // Limpiar todo
            $this->clearCart();

            Notification::make()
                ->title('Venta y factura generadas')
                ->body("Factura {$invoice->display_number} — L " . number_format((float) $sale->total, 2))
                ->success()
                ->actions([
                    \Filament\Actions\Action::make('ver_factura')
                        ->label('Ver / Imprimir Factura')
                        ->url(route('invoices.print', $invoice))
                        ->openUrlInNewTab()
                        ->button(),
                    \Filament\Actions\Action::make('ver_venta')
                        ->label('Ver Venta')
                        ->url(route('filament.admin.resources.sales.view', $sale))
                        ->button(),
                ])
                ->persistent()
                ->send();

        } catch (NoHayCajaAbiertaException $e) {
            // Invariante del POS: toda venta nace dentro de una sesión de
            // caja abierta (C2). Surfacear mensaje accionable — el cajero
            // resuelve esto yendo a abrir su caja, no reintentando la venta.
            Notification::make()
                ->title('No hay caja abierta')
                ->body('Abrí tu sesión de caja del día antes de procesar ventas.')
                ->danger()
                ->persistent()
                ->send();
        } catch (NoActiveEstablishmentException $e) {
            // Caso separado del RuntimeException genérico para surfacear un
            // mensaje accionable al cajero: esto se resuelve editando el user,
            // no reintentando la venta.
            Notification::make()
                ->title('Sucursal no configurada')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        } catch (\RuntimeException $e) {
            Notification::make()
                ->title('Error al procesar')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
