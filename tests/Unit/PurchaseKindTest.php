<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PurchaseKind;
use App\Enums\SupplierDocumentType;
use PHPUnit\Framework\TestCase;

/**
 * Documento que el formulario de Compras preselecciona al elegir el tipo.
 */
class PurchaseKindTest extends TestCase
{
    public function test_un_gasto_sugiere_recibo_interno(): void
    {
        $this->assertSame(SupplierDocumentType::ReciboInterno, PurchaseKind::Gasto->suggestedDocumentType());
    }

    public function test_la_mercaderia_sugiere_factura(): void
    {
        $this->assertSame(SupplierDocumentType::Factura, PurchaseKind::Mercaderia->suggestedDocumentType());
    }
}
