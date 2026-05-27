<?php

namespace Tests\Feature\Filament;

use App\Enums\ProductCondition;
use App\Enums\TaxType;
use App\Filament\Resources\Products\Pages\CreateProduct;
use Tests\TestCase;

/**
 * Tests para `CreateProduct::convertPricesToBase`.
 *
 * Bug histórico (2026-05-04): el form tenía dos campos con key 'tax_type'
 * (Hidden con default Gravado15 para físicos, Select con default Exento
 * para servicios). Filament aplica los defaults de AMBOS al inicializar
 * el state del form, sin importar si están visibles. Cuál gana al guardar
 * depende del orden de procesamiento interno de Filament — bug latente
 * que aparecía en distintas combinaciones de campos.
 *
 * Fix definitivo (2026-05-04): tanto `Product::enforceTaxType` (modelo)
 * como `CreateProduct::isGravadoFromFormData` (form) ahora derivan
 * tax_type de is_service + condition para productos físicos. NO se confía
 * en data['tax_type'] del form para físicos. Sólo se respeta para
 * servicios (donde el Select tax_type sí está expuesto al usuario).
 *
 * Reglas canónicas:
 *   - Servicio (is_service=true): respetar data['tax_type'].
 *   - Físico (enum o custom no-servicio): derivar de condition.
 *     Nuevo = Gravado15, Usado = Exento.
 *
 * Estos tests fijan el contrato del fix — son unit tests puros sin DB.
 */
class CreateProductConvertPricesTest extends TestCase
{
    public function test_convierte_sale_price_para_producto_enum_nuevo_aunque_tax_type_venga_exento(): void
    {
        // Simula el bug: tax_type='exento' contaminado pero el producto es
        // realmente Gravado15 (condition=new + product_type=enum).
        $data = [
            'product_type' => 'component',
            'condition' => ProductCondition::New->value,
            'is_service' => false,
            'tax_type' => TaxType::Exento->value,  // ← contaminado por Select fantasma
            'sale_price' => 2300,
            'cost_price' => 1000,
        ];

        $result = CreateProduct::convertPricesToBase($data);

        $this->assertEquals(2000.00, $result['sale_price'],
            'sale_price debe convertirse a NETO (2300/1.15) aunque tax_type del form mienta.');
        $this->assertEquals(1000, $result['cost_price'],
            'cost_price NO se toca — es el NETO en libros.');
    }

    public function test_convierte_sale_price_para_producto_enum_nuevo_con_tax_type_correcto(): void
    {
        // Caso happy path: todos los campos coherentes.
        $data = [
            'product_type' => 'component',
            'condition' => ProductCondition::New->value,
            'is_service' => false,
            'tax_type' => TaxType::Gravado15->value,
            'sale_price' => 2300,
            'cost_price' => 1000,
        ];

        $result = CreateProduct::convertPricesToBase($data);

        $this->assertEquals(2000.00, $result['sale_price']);
        $this->assertEquals(1000, $result['cost_price']);
    }

    public function test_no_convierte_sale_price_para_producto_enum_usado(): void
    {
        // Producto Usado = Exento (regla del dominio: bienes usados sin ISV).
        $data = [
            'product_type' => 'laptop',
            'condition' => ProductCondition::Used->value,
            'is_service' => false,
            'tax_type' => TaxType::Exento->value,
            'sale_price' => 5000,
            'cost_price' => 3000,
        ];

        $result = CreateProduct::convertPricesToBase($data);

        $this->assertEquals(5000, $result['sale_price'],
            'Productos Usados son Exentos: sale_price queda igual, sin back-out.');
    }

    public function test_no_convierte_sale_price_para_servicio_exento(): void
    {
        // Servicio Exento (Honorarios): is_service=true + tax_type=Exento.
        $data = [
            'product_type' => 'HONORARIOS',
            'condition' => ProductCondition::New->value,
            'is_service' => true,
            'tax_type' => TaxType::Exento->value,
            'sale_price' => 500,
            'cost_price' => 0,
        ];

        $result = CreateProduct::convertPricesToBase($data);

        $this->assertEquals(500, $result['sale_price'],
            'Servicios Exentos: sale_price no se convierte.');
    }

    public function test_convierte_sale_price_para_servicio_gravado(): void
    {
        // Servicio raro pero posible: gravado15 (Select expuesto al usuario).
        // El usuario eligió explícitamente Gravado15 desde el form, así que
        // sí aplica back-out.
        $data = [
            'product_type' => 'CONSULTORIA',
            'condition' => ProductCondition::New->value,
            'is_service' => true,
            'tax_type' => TaxType::Gravado15->value,
            'sale_price' => 1150,
            'cost_price' => 0,
        ];

        $result = CreateProduct::convertPricesToBase($data);

        $this->assertEquals(1000.00, $result['sale_price'],
            'Servicio Gravado15 (raro pero válido): sí aplica back-out.');
    }

    public function test_convierte_sale_price_para_custom_no_servicio_nuevo(): void
    {
        // EQUIPO DE SEGURIDAD nuevo: tipo custom no-servicio, condition=Nuevo.
        // Bug 2026-05-04: el form mandaba tax_type='exento' por contaminación
        // del Select fantasma. Antes se respetaba ese tax_type → el producto
        // quedaba mal almacenado (sale_price sin back-out).
        // Fix: derivar de condition igual que enum, ignorar tax_type del form.
        $data = [
            'product_type' => 'EQUIPO DE SEGURIDAD',
            'condition' => ProductCondition::New->value,
            'is_service' => false,
            'tax_type' => TaxType::Exento->value,  // ← contaminado, ignorado
            'sale_price' => 5175,  // 4500 NETO con ISV
            'cost_price' => 3000,
        ];

        $result = CreateProduct::convertPricesToBase($data);

        $this->assertEquals(4500.00, $result['sale_price'],
            'Custom no-servicio Nuevo: deriva de condition, aplica back-out.');
    }

    public function test_no_convierte_sale_price_para_custom_no_servicio_usado(): void
    {
        // Producto custom no-servicio Usado: deriva de condition → Exento.
        // Aunque tax_type venga Gravado15 desde el form, derivamos desde
        // condition: Used = Exento por dominio (Decreto 194-2002).
        $data = [
            'product_type' => 'EQUIPO DE SEGURIDAD',
            'condition' => ProductCondition::Used->value,
            'is_service' => false,
            'tax_type' => TaxType::Gravado15->value,  // ← inconsistente, ignorado
            'sale_price' => 3500,
            'cost_price' => 2000,
        ];

        $result = CreateProduct::convertPricesToBase($data);

        $this->assertEquals(3500, $result['sale_price'],
            'Custom no-servicio Usado: Exento por condition, sin back-out.');
    }

    public function test_convierte_correctamente_decimales_no_redondos(): void
    {
        // Verificar que el back-out preserva 4 decimales de precisión interna.
        // La columna products.sale_price es DECIMAL(12,4) — el back-out
        // intermedio NO debe truncar a 2 decimales (eso causaría el round-trip
        // lossy que se documenta en 2026_05_27_174348_increase_product_price_precision).
        $data = [
            'product_type' => 'laptop',
            'condition' => ProductCondition::New->value,
            'is_service' => false,
            'tax_type' => TaxType::Gravado15->value,
            'sale_price' => 1234.56,
            'cost_price' => 800,
        ];

        $result = CreateProduct::convertPricesToBase($data);

        // 1234.56 / 1.15 = 1073.5304347... redondeado a 4 = 1073.5304
        $this->assertEquals(1073.5304, $result['sale_price'],
            'El back-out debe preservar 4 decimales para que priceWithIsv reconstruya el original.');
    }
}
