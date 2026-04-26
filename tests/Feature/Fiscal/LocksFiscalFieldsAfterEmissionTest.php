<?php

namespace Tests\Feature\Fiscal;

use App\Exceptions\Fiscal\DocumentoFiscalInmutableException;
use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inmutabilidad fiscal post-emisión.
 *
 * Garantiza que un documento fiscal ya sellado (emitted_at != null) no
 * admite modificaciones en columnas protegidas. Esta es la red que impide
 * que un UPDATE mal dirigido desde Filament, tinker o un Action corrompa
 * el registro frente al SAR.
 */
class LocksFiscalFieldsAfterEmissionTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────────
    // Invoice
    // ────────────────────────────────────────────────────────────

    public function test_invoice_no_emitida_permite_cualquier_cambio(): void
    {
        $invoice = Invoice::factory()->create(['emitted_at' => null]);

        // No debe lanzar: el documento aún no está sellado.
        $invoice->update([
            'total'    => 9999.99,
            'subtotal' => 8000.00,
        ]);

        $this->assertEquals('9999.99', $invoice->fresh()->total);
    }

    public function test_invoice_emitida_bloquea_modificar_total(): void
    {
        $invoice = Invoice::factory()->create();

        $this->expectException(DocumentoFiscalInmutableException::class);

        $invoice->update(['total' => 9999.99]);
    }

    public function test_invoice_emitida_bloquea_modificar_numero_y_cai(): void
    {
        $invoice = Invoice::factory()->create();

        $this->expectException(DocumentoFiscalInmutableException::class);

        $invoice->update([
            'invoice_number' => '999-999-01-00000000',
            'cai'            => 'ZZZZZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZZZZZZZZZ',
        ]);
    }

    public function test_invoice_emitida_bloquea_modificar_snapshot_cliente(): void
    {
        $invoice = Invoice::factory()->create();

        $this->expectException(DocumentoFiscalInmutableException::class);

        $invoice->update(['customer_rtn' => '00000000000000']);
    }

    public function test_invoice_emitida_permite_anulacion(): void
    {
        $invoice = Invoice::factory()->create();

        // is_void está explícitamente en la whitelist.
        $invoice->update(['is_void' => true]);

        $this->assertTrue($invoice->fresh()->is_void);
    }

    public function test_invoice_emitida_permite_guardar_pdf_path(): void
    {
        $invoice = Invoice::factory()->create();

        // pdf_path se asigna async post-emisión (Job), está whitelisted.
        $invoice->update(['pdf_path' => 'facturas/1/factura-001.pdf']);

        $this->assertEquals('facturas/1/factura-001.pdf', $invoice->fresh()->pdf_path);
    }

    public function test_sellar_invoice_por_primera_vez_no_dispara_bloqueo(): void
    {
        // Simula el flujo real de InvoiceService: crea sin emitted_at, luego sella.
        $invoice = Invoice::factory()->create(['emitted_at' => null]);

        $invoice->emitted_at     = now();
        $invoice->integrity_hash = hash('sha256', 'test');
        $invoice->save();

        $this->assertNotNull($invoice->fresh()->emitted_at);
    }

    public function test_exception_incluye_metadata_util_para_diagnostico(): void
    {
        $invoice = Invoice::factory()->create();

        try {
            $invoice->update(['total' => 1, 'subtotal' => 1]);
            $this->fail('Se esperaba DocumentoFiscalInmutableException');
        } catch (DocumentoFiscalInmutableException $e) {
            $this->assertEquals('Invoice', $e->documentType);
            $this->assertEquals($invoice->id, $e->documentId);
            $this->assertContains('total', $e->dirtyFields);
            $this->assertContains('subtotal', $e->dirtyFields);
            // is_void/pdf_path/updated_at NO deben aparecer en dirtyFields bloqueados.
            $this->assertNotContains('updated_at', $e->dirtyFields);
        }
    }

    // ────────────────────────────────────────────────────────────
    // CreditNote (misma regla, otro documento)
    // ────────────────────────────────────────────────────────────

    public function test_credit_note_emitida_bloquea_modificar_total(): void
    {
        $nc = CreditNote::factory()->create();

        $this->expectException(DocumentoFiscalInmutableException::class);

        $nc->update(['total' => 9999.99]);
    }

    public function test_credit_note_emitida_bloquea_modificar_razon(): void
    {
        $nc = CreditNote::factory()->create();

        $this->expectException(DocumentoFiscalInmutableException::class);

        $nc->update(['reason' => \App\Enums\CreditNoteReason::CorreccionError]);
    }

    public function test_credit_note_emitida_bloquea_modificar_snapshot_factura_origen(): void
    {
        $nc = CreditNote::factory()->create();

        $this->expectException(DocumentoFiscalInmutableException::class);

        $nc->update(['original_invoice_number' => '999-999-01-99999999']);
    }

    public function test_credit_note_emitida_permite_anulacion(): void
    {
        $nc = CreditNote::factory()->create();

        $nc->update(['is_void' => true]);

        $this->assertTrue($nc->fresh()->is_void);
    }

    public function test_credit_note_no_emitida_permite_cualquier_cambio(): void
    {
        $nc = CreditNote::factory()->create(['emitted_at' => null]);

        $nc->update(['total' => 7777.77]);

        $this->assertEquals('7777.77', $nc->fresh()->total);
    }
}
