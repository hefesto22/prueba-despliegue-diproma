<?php

namespace Tests\Unit\Services\Sales;

use App\Enums\TaxType;
use App\Services\Sales\FreeSaleLine;
use Tests\TestCase;

/**
 * Línea libre de la factura especial: normaliza y valida en el constructor
 * para que una línea inválida nunca llegue a la transacción de la venta.
 */
class FreeSaleLineTest extends TestCase
{
    public function test_normaliza_descripcion_a_mayusculas_y_redondea_montos(): void
    {
        $line = new FreeSaleLine(
            description: '  Mantenimiento de rodos y limpieza epson L210 ',
            quantity: 2,
            unitPrice: 980.004,
            taxType: TaxType::Exento,
            unitCost: 150.555,
        );

        $this->assertSame('MANTENIMIENTO DE RODOS Y LIMPIEZA EPSON L210', $line->description);
        $this->assertSame(2, $line->quantity);
        $this->assertSame(980.0, $line->unitPrice);
        $this->assertSame(150.56, $line->unitCost);
        $this->assertSame(TaxType::Exento, $line->taxType);
    }

    public function test_el_costo_es_opcional(): void
    {
        $line = new FreeSaleLine('Reseteo epson L3110', 1, 1050, TaxType::Gravado15);

        $this->assertNull($line->unitCost);
    }

    public function test_rechaza_descripcion_vacia(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FreeSaleLine('   ', 1, 100, TaxType::Exento);
    }

    public function test_rechaza_descripcion_mas_larga_que_la_columna(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FreeSaleLine(str_repeat('A', FreeSaleLine::MAX_DESCRIPTION_LENGTH + 1), 1, 100, TaxType::Exento);
    }

    public function test_rechaza_cantidad_menor_a_uno(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FreeSaleLine('Servicio', 0, 100, TaxType::Exento);
    }

    public function test_rechaza_precio_cero(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FreeSaleLine('Servicio', 1, 0, TaxType::Exento);
    }

    public function test_rechaza_costo_negativo(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FreeSaleLine('Servicio', 1, 100, TaxType::Exento, unitCost: -1);
    }
}
