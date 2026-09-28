<?php

namespace Tests\Unit;

use App\Enums\SupplierDocumentType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Normalización del state de formulario a enum (SupplierDocumentType::fromState).
 *
 * Cubre las formas reales en que Filament entrega `document_type`: enum ya
 * hidratado, string, y el int 99 que produce PHP al usar '99' como llave del
 * array de opciones del Select.
 */
class SupplierDocumentTypeTest extends TestCase
{
    #[Test]
    public function from_state_normaliza_todas_las_formas_del_formulario(): void
    {
        $this->assertSame(SupplierDocumentType::ReciboInterno, SupplierDocumentType::fromState(SupplierDocumentType::ReciboInterno));
        $this->assertSame(SupplierDocumentType::ReciboInterno, SupplierDocumentType::fromState('99'));
        $this->assertSame(SupplierDocumentType::ReciboInterno, SupplierDocumentType::fromState(99));
        $this->assertSame(SupplierDocumentType::Factura, SupplierDocumentType::fromState('01'));
    }

    #[Test]
    public function from_state_devuelve_null_para_vacios_o_desconocidos(): void
    {
        $this->assertNull(SupplierDocumentType::fromState(null));
        $this->assertNull(SupplierDocumentType::fromState(''));
        $this->assertNull(SupplierDocumentType::fromState('77'));
        $this->assertNull(SupplierDocumentType::fromState(1.5));
    }

    #[Test]
    public function is_recibo_interno_acepta_string_int_y_enum(): void
    {
        $this->assertTrue(SupplierDocumentType::isReciboInterno('99'));
        $this->assertTrue(SupplierDocumentType::isReciboInterno(99));
        $this->assertTrue(SupplierDocumentType::isReciboInterno(SupplierDocumentType::ReciboInterno));
        $this->assertFalse(SupplierDocumentType::isReciboInterno('01'));
        $this->assertFalse(SupplierDocumentType::isReciboInterno(null));
    }
}
