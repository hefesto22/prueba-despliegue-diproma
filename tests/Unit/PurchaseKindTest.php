<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PaymentMethod;
use App\Enums\PurchaseKind;
use App\Enums\SupplierDocumentType;
use PHPUnit\Framework\TestCase;

/**
 * Sugerencias que el formulario de Compras preselecciona al elegir el tipo.
 */
class PurchaseKindTest extends TestCase
{
    public function test_un_gasto_sugiere_recibo_interno_pagado_en_efectivo(): void
    {
        $this->assertSame(SupplierDocumentType::ReciboInterno, PurchaseKind::Gasto->suggestedDocumentType());
        $this->assertSame(PaymentMethod::Efectivo, PurchaseKind::Gasto->suggestedPaymentMethod());
    }

    public function test_la_mercaderia_sugiere_factura_sin_forma_de_pago(): void
    {
        $this->assertSame(SupplierDocumentType::Factura, PurchaseKind::Mercaderia->suggestedDocumentType());
        $this->assertNull(PurchaseKind::Mercaderia->suggestedPaymentMethod());
    }
}
