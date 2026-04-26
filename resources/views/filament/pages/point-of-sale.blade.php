<x-filament-panels::page>

    {{-- Botón "Ver carrito" con badge flotante --}}
    <div style="display: flex; justify-content: flex-end; margin-bottom: 1rem;">
        <button
            wire:click="$dispatch('open-modal', { id: 'carrito-modal' })"
            style="
                position: relative;
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                padding: 0.5rem 1rem;
                background-color: rgb(217 119 6);
                color: white;
                font-size: 0.875rem;
                font-weight: 600;
                border-radius: 0.5rem;
                border: none;
                cursor: pointer;
                box-shadow: 0 1px 3px rgba(0,0,0,0.12);
                transition: background-color 0.15s;
            "
            onmouseover="this.style.backgroundColor='rgb(180 83 9)'"
            onmouseout="this.style.backgroundColor='rgb(217 119 6)'"
        >
            <x-filament::icon icon="heroicon-o-shopping-cart" style="width: 1.25rem; height: 1.25rem;" />
            Ver carrito
            @if($this->cartItemCount > 0)
                <span style="
                    position: absolute;
                    top: -0.5rem;
                    right: -0.5rem;
                    background: rgb(220 38 38);
                    color: white;
                    font-size: 0.75rem;
                    font-weight: 700;
                    padding: 0.125rem 0.5rem;
                    border-radius: 9999px;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
                    border: 2px solid white;
                    min-width: 1.25rem;
                    text-align: center;
                ">
                    {{ $this->cartItemCount }}
                </span>
            @endif
        </button>
    </div>

    {{-- Tabla de productos con paginación, búsqueda y filtros nativos de Filament --}}
    {{ $this->table }}

    {{-- ═══ MODAL SLIDE-OVER: Carrito de Compras ═══ --}}
    <x-filament::modal id="carrito-modal" width="5xl" slide-over>
        <x-slot name="heading">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <x-filament::icon icon="heroicon-o-shopping-cart" style="width: 1.5rem; height: 1.5rem; color: rgb(217 119 6);" />
                <span style="font-size: 1.25rem; font-weight: 600;">Carrito de Compras</span>
            </div>
        </x-slot>

        <div style="padding: 0; display: flex; flex-direction: column; gap: 1.5rem;">

            @if(count($cart) > 0)
                {{-- ═══ Tabla de items del carrito ═══ --}}
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                        <thead>
                            <tr style="background: rgb(249 250 251); border-bottom: 1px solid rgb(229 231 235);">
                                <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: rgb(55 65 81);">Producto</th>
                                <th style="padding: 0.75rem; text-align: right; font-weight: 600; color: rgb(55 65 81);">Precio</th>
                                <th style="padding: 0.75rem; text-align: center; font-weight: 600; color: rgb(55 65 81);">ISV</th>
                                <th style="padding: 0.75rem; text-align: center; font-weight: 600; color: rgb(55 65 81);">Cant.</th>
                                <th style="padding: 0.75rem; text-align: right; font-weight: 600; color: rgb(55 65 81);">Total</th>
                                <th style="padding: 0.75rem; text-align: center; font-weight: 600; color: rgb(55 65 81);"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($cart as $index => $item)
                                @php
                                    $lineTotal = $item['unit_price'] * $item['quantity'];
                                    $isGravado = $item['tax_type'] === 'gravado_15';
                                @endphp
                                <tr wire:key="cart-row-{{ $index }}" style="border-bottom: 1px solid rgb(243 244 246);">
                                    {{-- Producto --}}
                                    <td style="padding: 0.75rem;">
                                        <div style="font-weight: 500; color: rgb(17 24 39);">{{ $item['name'] }}</div>
                                        <div style="font-size: 0.75rem; color: rgb(107 114 128); font-family: monospace;">{{ $item['sku'] }}</div>
                                    </td>
                                    {{-- Precio --}}
                                    <td style="padding: 0.75rem; text-align: right; color: rgb(55 65 81);">
                                        L {{ number_format($item['unit_price'], 2) }}
                                    </td>
                                    {{-- ISV --}}
                                    <td style="padding: 0.75rem; text-align: center;">
                                        @php
                                            $badgeStyle = $isGravado
                                                ? 'display:inline-block;font-size:0.75rem;padding:0.125rem 0.5rem;border-radius:9999px;background:rgb(219 234 254);color:rgb(29 78 216);'
                                                : 'display:inline-block;font-size:0.75rem;padding:0.125rem 0.5rem;border-radius:9999px;background:rgb(243 244 246);color:rgb(107 114 128);';
                                        @endphp
                                        <span style="{{ $badgeStyle }}">
                                            {{ $isGravado ? '15%' : 'Exento' }}
                                        </span>
                                    </td>
                                    {{-- Cantidad --}}
                                    <td style="padding: 0.75rem; text-align: center;">
                                        <div style="display: inline-flex; align-items: center; gap: 0.25rem;">
                                            <button
                                                wire:click="updateQuantity({{ $index }}, {{ $item['quantity'] - 1 }})"
                                                style="width: 1.75rem; height: 1.75rem; display: flex; align-items: center; justify-content: center; border-radius: 0.375rem; background: rgb(229 231 235); border: none; cursor: pointer; font-weight: 700; font-size: 0.875rem; color: rgb(55 65 81);"
                                            >&minus;</button>
                                            <span style="width: 2rem; text-align: center; font-weight: 600;">{{ $item['quantity'] }}</span>
                                            <button
                                                wire:click="updateQuantity({{ $index }}, {{ $item['quantity'] + 1 }})"
                                                style="width: 1.75rem; height: 1.75rem; display: flex; align-items: center; justify-content: center; border-radius: 0.375rem; background: rgb(229 231 235); border: none; cursor: pointer; font-weight: 700; font-size: 0.875rem; color: rgb(55 65 81);"
                                            >+</button>
                                        </div>
                                    </td>
                                    {{-- Total --}}
                                    <td style="padding: 0.75rem; text-align: right; font-weight: 600; color: rgb(217 119 6);">
                                        L {{ number_format($lineTotal, 2) }}
                                    </td>
                                    {{-- Eliminar --}}
                                    <td style="padding: 0.75rem; text-align: center;">
                                        <button
                                            wire:click="removeFromCart({{ $index }})"
                                            style="background: none; border: none; cursor: pointer; color: rgb(239 68 68); font-size: 0.875rem;"
                                            title="Eliminar"
                                        >
                                            <x-filament::icon icon="heroicon-o-trash" style="width: 1rem; height: 1rem;" />
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- ═══ Resumen Fiscal + Descuento ═══ --}}
                <div style="display: grid; grid-template-columns: 1fr; gap: 1rem;">
                    @php $breakdown = $this->taxBreakdown; @endphp

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        {{-- Desglose ISV --}}
                        <div style="background: rgb(249 250 251); border-radius: 0.75rem; padding: 1rem; border: 1px solid rgb(229 231 235);">
                            <div style="display: flex; justify-content: space-between; padding: 0.25rem 0; color: rgb(75 85 99); font-size: 0.875rem;">
                                <span>Subtotal (sin ISV)</span>
                                <span style="font-weight: 600;">L {{ number_format($breakdown['subtotal'], 2) }}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 0.25rem 0; color: rgb(75 85 99); font-size: 0.875rem;">
                                <span>ISV 15%</span>
                                <span style="font-weight: 600;">L {{ number_format($breakdown['isv'], 2) }}</span>
                            </div>
                            <div style="border-top: 1px solid rgb(209 213 219); margin-top: 0.5rem; padding-top: 0.5rem; display: flex; justify-content: space-between; color: rgb(55 65 81); font-size: 0.875rem;">
                                <span>Bruto</span>
                                <span style="font-weight: 600;">L {{ number_format($this->cartGrossTotal, 2) }}</span>
                            </div>
                        </div>

                        {{-- Descuento + Total Final --}}
                        <div style="background: rgb(249 250 251); border-radius: 0.75rem; padding: 1rem; border: 1px solid rgb(229 231 235);">
                            <label style="display: block; font-size: 0.875rem; font-weight: 500; color: rgb(55 65 81); margin-bottom: 0.5rem;">Descuento</label>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.75rem;">
                                <select
                                    wire:model.live="discountType"
                                    style="padding: 0.5rem; border-radius: 0.375rem; border: 1px solid rgb(209 213 219); font-size: 0.875rem; background: white;"
                                >
                                    <option value="">Sin descuento</option>
                                    <option value="percentage">Porcentaje (%)</option>
                                    <option value="fixed">Monto Fijo (L)</option>
                                </select>
                                @php
                                    $inputStyle = 'padding:0.5rem;border-radius:0.375rem;border:1px solid rgb(209 213 219);font-size:0.875rem;';
                                    if (blank($discountType)) {
                                        $inputStyle .= 'opacity:0.5;';
                                    }
                                @endphp
                                <input
                                    type="number"
                                    wire:model.live.debounce.500ms="discountValue"
                                    placeholder="{{ $discountType === 'percentage' ? 'Ej: 10' : 'Ej: 200' }}"
                                    style="{{ $inputStyle }}"
                                    {{ blank($discountType) ? 'disabled' : '' }}
                                    min="0"
                                    step="0.01"
                                />
                            </div>
                            @if($this->discountAmount > 0)
                                <div style="display: flex; justify-content: space-between; padding: 0.25rem 0; color: rgb(220 38 38); font-size: 0.875rem;">
                                    <span>Descuento {{ $discountType === 'percentage' ? "({$discountValue}%)" : '' }}</span>
                                    <span style="font-weight: 600;">-L {{ number_format($this->discountAmount, 2) }}</span>
                                </div>
                            @endif
                            <div style="border-top: 2px solid rgb(209 213 219); margin-top: 0.5rem; padding-top: 0.5rem; display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-size: 1.125rem; font-weight: 700; color: rgb(17 24 39);">TOTAL</span>
                                <span style="font-size: 1.25rem; font-weight: 700; color: rgb(217 119 6);">L {{ number_format($breakdown['total'], 2) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ═══ Datos del Cliente ═══ --}}
                <div style="background: rgb(249 250 251); border-radius: 0.75rem; padding: 1rem; border: 1px solid rgb(229 231 235);">
                    <h3 style="font-size: 1rem; font-weight: 600; color: rgb(55 65 81); margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                        <x-filament::icon icon="heroicon-o-user" style="width: 1.25rem; height: 1.25rem;" />
                        Datos del Cliente
                    </h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                        <div>
                            <label style="display: block; font-size: 0.75rem; color: rgb(107 114 128); margin-bottom: 0.25rem;">Nombre</label>
                            <input
                                type="text"
                                wire:model.blur="customerName"
                                placeholder="Consumidor Final"
                                style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.375rem; border: 1px solid rgb(209 213 219); font-size: 0.875rem; background: white;"
                            />
                        </div>
                        <div>
                            <label style="display: block; font-size: 0.75rem; color: rgb(107 114 128); margin-bottom: 0.25rem;">RTN (opcional)</label>
                            <input
                                type="text"
                                wire:model.blur="customerRtn"
                                placeholder="0000-0000-00000"
                                style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.375rem; border: 1px solid rgb(209 213 219); font-size: 0.875rem; background: white;"
                            />
                        </div>
                        <div style="grid-column: span 2;">
                            <label style="display: block; font-size: 0.75rem; color: rgb(107 114 128); margin-bottom: 0.25rem;">Notas (opcional)</label>
                            <input
                                type="text"
                                wire:model.blur="notes"
                                placeholder="Observaciones de la venta"
                                style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.375rem; border: 1px solid rgb(209 213 219); font-size: 0.875rem; background: white;"
                            />
                        </div>
                    </div>
                    <p style="font-size: 0.75rem; color: rgb(156 163 175); margin-top: 0.5rem;">
                        Si ingresa RTN, el cliente se guarda automáticamente para futuras ventas.
                    </p>
                </div>

                {{-- ═══ Método de Pago ═══ --}}
                {{--
                    Radio chips estilo tarjeta — uno por cada caso del enum PaymentMethod.
                    Solo `efectivo` afecta el saldo de caja (regla de dominio: affectsCashBalance()).
                    El valor enlazado a Livewire es el `value` del enum (string) y se convierte
                    a PaymentMethod::from() en el backend antes de pasarlo al SaleService.
                --}}
                <div style="background: rgb(249 250 251); border-radius: 0.75rem; padding: 1rem; border: 1px solid rgb(229 231 235);">
                    <h3 style="font-size: 1rem; font-weight: 600; color: rgb(55 65 81); margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                        <x-filament::icon icon="heroicon-o-banknotes" style="width: 1.25rem; height: 1.25rem;" />
                        Método de Pago
                    </h3>
                    <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 0.5rem;">
                        @foreach(\App\Enums\PaymentMethod::cases() as $method)
                            @php
                                $isSelected = $paymentMethod === $method->value;
                                $chipStyle = 'display:flex;flex-direction:column;align-items:center;justify-content:center;gap:0.375rem;padding:0.75rem 0.5rem;border-radius:0.5rem;cursor:pointer;transition:all 0.15s;text-align:center;';
                                $chipStyle .= $isSelected
                                    ? 'background:rgb(254 243 199);border:2px solid rgb(217 119 6);box-shadow:0 1px 3px rgba(217,119,6,0.2);'
                                    : 'background:white;border:2px solid rgb(229 231 235);';
                                $iconColor = $isSelected ? 'rgb(180 83 9)' : 'rgb(107 114 128)';
                                $labelColor = $isSelected ? 'rgb(113 63 18)' : 'rgb(75 85 99)';
                                $labelWeight = $isSelected ? '600' : '500';
                            @endphp
                            <label wire:key="payment-method-{{ $method->value }}" style="{{ $chipStyle }}">
                                <input
                                    type="radio"
                                    wire:model.live="paymentMethod"
                                    value="{{ $method->value }}"
                                    style="position:absolute;opacity:0;pointer-events:none;"
                                />
                                <x-filament::icon
                                    :icon="$method->getIcon()"
                                    style="width: 1.5rem; height: 1.5rem; color: {{ $iconColor }};"
                                />
                                <span style="font-size: 0.75rem; font-weight: {{ $labelWeight }}; color: {{ $labelColor }}; line-height: 1.2;">
                                    {{ $method->getLabel() }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @if($paymentMethod !== 'efectivo')
                        <p style="font-size: 0.75rem; color: rgb(107 114 128); margin-top: 0.5rem; display: flex; align-items: center; gap: 0.25rem;">
                            <x-filament::icon icon="heroicon-o-information-circle" style="width: 0.875rem; height: 0.875rem; display: inline;" />
                            Este método no afecta el saldo físico de caja — se registra para reporte fiscal.
                        </p>
                    @endif
                </div>

                {{-- ═══ Opción Sin CAI ═══ --}}
                <div style="background: rgb(254 252 232); border-radius: 0.75rem; padding: 0.75rem 1rem; border: 1px solid rgb(250 204 21);">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input
                            type="checkbox"
                            wire:model.live="withoutCai"
                            style="width: 1rem; height: 1rem; accent-color: rgb(217 119 6); cursor: pointer;"
                        />
                        <span style="font-size: 0.875rem; font-weight: 500; color: rgb(113 63 18);">
                            Factura sin CAI
                        </span>
                    </label>
                    <p style="font-size: 0.75rem; color: rgb(161 98 7); margin-top: 0.25rem; margin-left: 1.5rem;">
                        Marcar si no se requiere número fiscal autorizado (SAR). Se usará un número interno de referencia.
                    </p>
                </div>

            @else
                {{-- Carrito vacío --}}
                <div style="text-align: center; padding: 3rem 0; color: rgb(156 163 175);">
                    <x-filament::icon icon="heroicon-o-shopping-cart" style="width: 3rem; height: 3rem; margin: 0 auto 0.75rem; opacity: 0.5;" />
                    <p style="font-size: 1rem; font-weight: 500;">El carrito está vacío</p>
                    <p style="font-size: 0.875rem;">Agrega productos desde la tabla para comenzar una venta</p>
                </div>
            @endif

        </div>

        {{-- ═══ Footer del modal ═══ --}}
        <x-slot name="footer">
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; flex-wrap: wrap;">
                @if(count($cart) > 0)
                    <x-filament::button color="danger" wire:click="clearCart">
                        <x-filament::icon icon="heroicon-o-trash" style="width: 1rem; height: 1rem; display: inline; margin-right: 0.25rem;" />
                        Descartar venta
                    </x-filament::button>

                    <button
                        wire:click="processSale"
                        wire:loading.attr="disabled"
                        wire:target="processSale"
                        style="
                            display: inline-flex;
                            align-items: center;
                            gap: 0.5rem;
                            padding: 0.625rem 1.5rem;
                            background: rgb(16 185 129);
                            color: white;
                            font-weight: 600;
                            font-size: 0.875rem;
                            border-radius: 0.5rem;
                            border: none;
                            cursor: pointer;
                            transition: background-color 0.15s;
                        "
                        onmouseover="this.style.backgroundColor='rgb(5 150 105)'"
                        onmouseout="this.style.backgroundColor='rgb(16 185 129)'"
                    >
                        <span wire:loading.remove wire:target="processSale">
                            <x-filament::icon icon="heroicon-o-check-circle" style="width: 1.25rem; height: 1.25rem; display: inline;" />
                            Procesar Venta
                        </span>
                        <span wire:loading wire:target="processSale">
                            Procesando...
                        </span>
                    </button>
                @endif
            </div>
        </x-slot>
    </x-filament::modal>

</x-filament-panels::page>
