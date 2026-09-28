<?php

namespace Tests\Unit\Services\Purchases;

use App\Enums\SupplierDocumentType;
use App\Services\Purchases\Exceptions\MontosDocumentoInvalidosException;
use App\Services\Purchases\PurchaseDocumentAmounts;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests puros del Value Object de montos de un documento de compra.
 * Sin base de datos: solo aritmética y reglas.
 */
class PurchaseDocumentAmountsTest extends TestCase
{
    #[Test]
    public function factura_mixta_deriva_subtotal_y_total(): void
    {
        $amounts = PurchaseDocumentAmounts::forDocument(SupplierDocumentType::Factura, 1000, 200, 150);

        $this->assertSame([
            'subtotal' => 1200.0,
            'taxable_total' => 1000.0,
            'exempt_total' => 200.0,
            'isv' => 150.0,
            'total' => 1350.0,
        ], $amounts->toAttributes());
    }

    #[Test]
    public function factura_solo_exenta_es_valida(): void
    {
        $amounts = PurchaseDocumentAmounts::forDocument(SupplierDocumentType::Factura, 0, 850, 0);

        $this->assertSame(850.0, $amounts->total());
        $this->assertTrue($amounts->isvMatchesRate());
    }

    #[Test]
    public function recibo_interno_registra_todo_como_exento_sin_isv(): void
    {
        $amounts = PurchaseDocumentAmounts::forDocument(SupplierDocumentType::ReciboInterno, 0, 500, 0);

        $this->assertSame(0.0, $amounts->taxable);
        $this->assertSame(500.0, $amounts->exempt);
        $this->assertSame(0.0, $amounts->isv);
        $this->assertSame(500.0, $amounts->total());
    }

    #[Test]
    public function recibo_interno_suma_gravado_e_isv_si_llegaran_porque_todo_es_precio_final(): void
    {
        $amounts = PurchaseDocumentAmounts::forDocument(SupplierDocumentType::ReciboInterno, 100, 50, 15);

        $this->assertSame(['subtotal' => 165.0, 'taxable_total' => 0.0, 'exempt_total' => 165.0, 'isv' => 0.0, 'total' => 165.0],
            $amounts->toAttributes());
    }

    #[Test]
    public function recibo_interno_en_cero_se_rechaza_en_el_campo_total_pagado(): void
    {
        $this->assertRejectedOn('exempt_total', fn () => PurchaseDocumentAmounts::forDocument(SupplierDocumentType::ReciboInterno, 0, 0, 0));
    }

    #[Test]
    public function factura_en_cero_se_rechaza(): void
    {
        $this->assertRejectedOn('taxable_total', fn () => PurchaseDocumentAmounts::forDocument(SupplierDocumentType::Factura, 0, 0, 0));
    }

    #[Test]
    public function isv_sin_importe_gravado_se_rechaza(): void
    {
        $this->assertRejectedOn('isv', fn () => PurchaseDocumentAmounts::forDocument(SupplierDocumentType::Factura, 0, 500, 15));
    }

    #[Test]
    public function isv_mayor_que_el_gravado_se_rechaza(): void
    {
        $this->assertRejectedOn('isv', fn () => PurchaseDocumentAmounts::forDocument(SupplierDocumentType::Factura, 100, 0, 150));
    }

    #[Test]
    public function montos_negativos_se_rechazan_en_su_propio_campo(): void
    {
        $this->assertRejectedOn('exempt_total', fn () => PurchaseDocumentAmounts::forDocument(SupplierDocumentType::Factura, 100, -1, 15));
        $this->assertRejectedOn('taxable_total', fn () => PurchaseDocumentAmounts::forDocument(SupplierDocumentType::ReciboInterno, -5, 100, 0));
    }

    #[Test]
    public function isv_sugerido_es_el_15_por_ciento_redondeado(): void
    {
        $this->assertSame(150.0, PurchaseDocumentAmounts::suggestedIsv(1000));
        $this->assertSame(1.85, PurchaseDocumentAmounts::suggestedIsv(12.33)); // 1.8495 → 1.85
        $this->assertSame(0.0, PurchaseDocumentAmounts::suggestedIsv(-10));
    }

    #[Test]
    public function diferencia_de_isv_dentro_de_la_tolerancia_se_considera_redondeo(): void
    {
        // Proveedor que redondea línea por línea: 2 centavos arriba.
        $amounts = PurchaseDocumentAmounts::forDocument(SupplierDocumentType::Factura, 1000, 0, 150.02);

        $this->assertSame(0.02, $amounts->isvDifference());
        $this->assertTrue($amounts->isvMatchesRate());
    }

    #[Test]
    public function diferencia_de_isv_fuera_de_la_tolerancia_se_informa_sin_bloquear(): void
    {
        $amounts = PurchaseDocumentAmounts::forDocument(SupplierDocumentType::Factura, 1000, 0, 149.97);

        $this->assertSame(-0.03, $amounts->isvDifference());
        $this->assertFalse($amounts->isvMatchesRate());
        $this->assertSame(149.97, $amounts->isv, 'Se guarda lo que dice el documento, no el recálculo.');
    }

    #[Test]
    public function montos_se_redondean_a_centavos(): void
    {
        $amounts = PurchaseDocumentAmounts::forDocument(SupplierDocumentType::Factura, 100.005, 0.004, 15.001);

        $this->assertSame(100.01, $amounts->taxable);
        $this->assertSame(0.0, $amounts->exempt);
        $this->assertSame(15.0, $amounts->isv);
    }

    #[Test]
    public function from_form_data_trata_montos_ausentes_como_cero(): void
    {
        // En RI el formulario oculta gravado e ISV: no viajan en el payload.
        $amounts = PurchaseDocumentAmounts::fromFormData([
            'document_type' => SupplierDocumentType::ReciboInterno->value,
            'exempt_total' => '320.50',
        ]);

        $this->assertSame(320.5, $amounts->total());
    }

    private function assertRejectedOn(string $field, callable $build): void
    {
        try {
            $build();
            $this->fail("Se esperaba MontosDocumentoInvalidosException en '{$field}'.");
        } catch (MontosDocumentoInvalidosException $e) {
            $this->assertSame($field, $e->field);
        }
    }
}
