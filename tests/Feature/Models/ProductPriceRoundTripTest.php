<?php

namespace Tests\Feature\Models;

use App\Enums\ProductCondition;
use App\Enums\ProductType;
use App\Enums\TaxType;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de regresión para el round-trip de precios con ISV.
 *
 * Bug reportado por el cliente (2026-05-27): al registrar o editar productos
 * con precio entero (ej. L 380.00), el sistema lo persiste y muestra como
 * L 379.99. Reproducible de forma intermitente — depende del valor exacto.
 *
 * Causa raíz: el form recibe precio público CON ISV. Para mantener la
 * convención NETO en BD, se aplica back-out (precio / 1.15). Con sale_price
 * almacenado en DECIMAL(12,2) el round-trip división → multiplicación pierde
 * información:
 *
 *   380.00 / 1.15 = 330.4347826...  → round(2) = 330.43
 *   330.43 × 1.15 = 379.9945        → round(2) = 379.99  ❌
 *
 * Fix: columna a DECIMAL(12,4) + Product::priceWithoutIsv redondea a 4.
 * El output a 2 decimales se mantiene solo en el punto de mostrar al usuario.
 *
 *   380.00 / 1.15 = 330.4347826...  → round(4) = 330.4348
 *   330.4348 × 1.15 = 380.00002     → round(2) = 380.00  ✓
 *
 * Estos tests fijan el contrato. Si alguna vez vuelven a romperse, alguien
 * destruyó la precisión interna en algún lugar (típicamente un round(x, 2)
 * intermedio sobre sale_price o cost_price). NO subir la columna otra vez a
 * DECIMAL(12,2) — buscar el round intermedio.
 */
class ProductPriceRoundTripTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Caso reportado por el cliente: L 380.00 ingresado → L 380.00 mostrado.
     */
    public function test_round_trip_380_no_pierde_centavo(): void
    {
        $base = Product::priceWithoutIsv(380.00);

        // Internamente: 380 / 1.15 = 330.4347826... → round(4) = 330.4348
        $this->assertEqualsWithDelta(330.4348, $base, 0.00001,
            'Back-out debe preservar 4 decimales para que el round-trip cuadre.');

        // Round-trip completo: el priceWithIsv(base) debe devolver 380.00 exacto.
        $this->assertEquals(380.00, Product::priceWithIsv($base),
            'Round-trip 380 → back-out → priceWithIsv debe reproducir 380.00.');
    }

    /**
     * Batería de precios reales del catálogo de Diproma (extraídos del seeder
     * y de casos típicos de retail hondureño). Ninguno debe perder centavo
     * en el round-trip — si alguno falla, el fix de precisión está roto.
     */
    public function test_precios_tipicos_retail_no_pierden_centavo_en_round_trip(): void
    {
        // Lista de precios públicos reales/comunes que el cliente cobra.
        $preciosPublicos = [
            100.00,    // mouse genérico
            195.00,    // memoria USB
            250.00,    // entrada simple
            295.00,    // mouse Logitech M190
            380.00,    // caso reportado
            450.00,    // teclado básico
            495.00,    // combo HP 235
            550.00,    // mochila Targus
            695.00,    // combo Logitech MK270
            850.00,    // SSD 480GB
            1100.00,   // memoria RAM Corsair
            1500.00,   // monitor 22"
            2200.00,   // monitor 24" usado
            3500.00,   // impresora usada
            5800.00,   // laptop usada básica
            11500.00,  // iPad 10ma
            13750.00,  // desktop Optiplex
            15500.00,  // PS5
            18975.00,  // laptop HP Probook
            23850.00,  // laptop Dell Latitude
        ];

        foreach ($preciosPublicos as $precio) {
            $base = Product::priceWithoutIsv($precio);
            $reconstruido = Product::priceWithIsv($base);

            $this->assertEquals(
                $precio,
                $reconstruido,
                "Round-trip de L {$precio} produjo L {$reconstruido} (base interna {$base}). Pierde centavo.",
            );
        }
    }

    /**
     * Cuando un producto se persiste con back-out + se rehídrata desde BD,
     * el accessor sale_price_with_isv debe devolver EXACTAMENTE el precio
     * público original que el usuario ingresó.
     *
     * Este test cubre el caso "guardar → leer", que es el viaje completo
     * que reportó el cliente: ingresa 380, guarda, abre el listado, ve 380.
     */
    public function test_persistir_y_leer_preserva_precio_publico_con_isv(): void
    {
        $category = Category::factory()->create();

        $precioPublicoIngresado = 380.00;

        // Simulamos el flujo de CreateProduct: form recibe con ISV, back-out
        // antes de persistir. La columna queda con 4 decimales.
        $product = Product::factory()->inCategory($category)->brandNew()->create([
            'product_type' => ProductType::Component->value,
            'sale_price' => Product::priceWithoutIsv($precioPublicoIngresado),
            'cost_price' => 200.00,
        ]);

        // Forzamos rehídratación desde BD (no usar la instancia cacheada en
        // memoria — eso no probaría el cast decimal:4).
        $fresh = Product::find($product->id);

        $this->assertEquals(
            $precioPublicoIngresado,
            $fresh->sale_price_with_isv,
            'El accessor debe reproducir el precio público original tras leer de BD.',
        );
    }

    /**
     * El cálculo de cantidad × precio en factura no debe acumular drift de
     * centavos. Si un cajero vende 10 unidades de un producto de L 380.00,
     * el total línea debe ser L 3800.00 exacto.
     *
     * Esto verifica que el accessor sale_price_with_isv (consumido por el POS
     * para inicializar el cart) no introduce pérdida que se amplifique al
     * multiplicar por cantidad.
     */
    public function test_cantidad_por_precio_no_acumula_drift_en_factura(): void
    {
        $category = Category::factory()->create();

        $product = Product::factory()->inCategory($category)->brandNew()->create([
            'product_type' => ProductType::Component->value,
            'sale_price' => Product::priceWithoutIsv(380.00),
            'cost_price' => 200.00,
        ]);

        $fresh = Product::find($product->id);

        $cantidad = 10;
        $totalLinea = round($fresh->sale_price_with_isv * $cantidad, 2);

        $this->assertEquals(3800.00, $totalLinea,
            '10 unidades de L 380.00 deben totalizar L 3800.00 exacto.');
    }

    /**
     * Productos Exentos (Usado, Honorarios) no aplican back-out. El sale_price
     * se guarda tal cual lo ingresó el usuario, y sale_price_with_isv lo
     * devuelve sin multiplicar. No debe haber pérdida ni siquiera teórica.
     */
    public function test_exento_no_aplica_back_out_y_preserva_precio_exacto(): void
    {
        $category = Category::factory()->create();

        $product = Product::factory()->inCategory($category)->used()->create([
            'product_type' => ProductType::Laptop->value,
            'sale_price' => 380.00,   // sin back-out — Exento
            'cost_price' => 200.00,
        ]);

        $fresh = Product::find($product->id);

        $this->assertEquals(380.00, $fresh->sale_price_with_isv,
            'Productos Exentos: precio público igual al sale_price almacenado.');
    }

    /**
     * Verificar explícitamente el contrato de los helpers estáticos:
     *   - priceWithoutIsv: 4 decimales internos (intermedio).
     *   - priceWithIsv:    2 decimales (output al usuario).
     *
     * Si alguien en el futuro cambia priceWithoutIsv a 2 decimales por
     * "elegancia", este test lo detecta de inmediato.
     */
    public function test_helpers_de_conversion_respetan_contrato_de_precision(): void
    {
        // priceWithoutIsv DEBE retornar 4 decimales — es valor intermedio
        // destinado a la columna DECIMAL(12,4). Para 380 los 4 decimales
        // significativos son 330.4348.
        $base = Product::priceWithoutIsv(380.00);
        $this->assertEqualsWithDelta(330.4348, $base, 0.00001);

        // priceWithIsv DEBE retornar 2 decimales — es valor de output al
        // usuario y/o snapshot persistido en columnas DECIMAL(12,2).
        $publico = Product::priceWithIsv(330.4348);
        $this->assertEquals(380.00, $publico);
    }
}
