<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();

            // ─── Relaciones ───────────────────────────────
            // invoice_id: factura que acredita. restrictOnDelete para preservar
            // trazabilidad fiscal — una NC no puede quedar huérfana.
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('cai_range_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained()->nullOnDelete();

            // ─── Datos fiscales del documento NC ──────────
            $table->string('credit_note_number', 25)->unique();  // XXX-XXX-03-XXXXXXXX
            $table->string('cai', 50)->nullable();
            $table->string('emission_point', 3)->nullable();
            $table->date('credit_note_date');
            $table->date('cai_expiration_date')->nullable();

            // ─── Razón y detalle ──────────────────────────
            // reason: CreditNoteReason enum. Castea a string en Eloquent.
            // reason_notes: obligatorio cuando reason != devolucion_fisica
            //   (lo valida el Service/FormRequest). En BD queda nullable para
            //   no duplicar esa regla en el schema.
            $table->string('reason');
            $table->text('reason_notes')->nullable();

            // ─── Snapshot emisor ──────────────────────────
            $table->string('company_name');
            $table->string('company_rtn', 20);
            $table->text('company_address');
            $table->string('company_phone')->nullable();
            $table->string('company_email')->nullable();

            // ─── Snapshot receptor ────────────────────────
            $table->string('customer_name');
            $table->string('customer_rtn', 20)->nullable();

            // ─── Snapshot de la factura origen ────────────
            // Redundante con invoice_id a propósito: si la factura original
            // cambiara por cualquier razón, la NC impresa debe seguir mostrando
            // los datos tal como estaban al momento de emitir.
            $table->string('original_invoice_number', 25);
            $table->string('original_invoice_cai', 50)->nullable();
            $table->date('original_invoice_date');

            // ─── Totales fiscales (snapshot, TODOS POSITIVOS) ─
            // Se emiten en positivo; el efecto de crédito es implícito por el
            // tipo de documento (NC). Esto facilita impresión SAR y evita
            // signos negativos en reportes que luego se reprocesan.
            $table->decimal('subtotal', 12, 2);
            $table->decimal('exempt_total', 12, 2)->default(0);
            $table->decimal('taxable_total', 12, 2);
            $table->decimal('isv', 12, 2);
            $table->decimal('total', 12, 2);

            // ─── Estado y meta ────────────────────────────
            $table->boolean('is_void')->default(false);
            $table->boolean('without_cai')->default(false);
            $table->string('pdf_path')->nullable();

            // ─── Integridad e inmutabilidad ───────────────
            // integrity_hash: SHA-256 sobre snapshot + id. Identifica el
            // documento en la URL pública de verificación (evita exponer el id).
            // emitted_at: mientras no sea NULL el Observer bloquea UPDATEs
            // fiscales. is_void NO participa del hash — anular es cambio de
            // estado, no del documento; los QR impresos siguen verificables.
            $table->string('integrity_hash', 64)->nullable();
            $table->timestamp('emitted_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // ─── Índices ──────────────────────────────────
            // (credit_note_date, is_void): listados filtrados por período/estado.
            // (establishment_id, credit_note_date): reportes por sucursal.
            // (invoice_id): buscar todas las NCs de una factura — query frecuente.
            $table->index(['credit_note_date', 'is_void']);
            $table->index(['establishment_id', 'credit_note_date']);
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
