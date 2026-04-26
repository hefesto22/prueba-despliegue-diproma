<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // ─── Relaciones ───────────────────────────────
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cai_range_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained()->nullOnDelete();

            // ─── Datos fiscales ───────────────────────────
            $table->string('invoice_number', 25)->unique();    // XXX-XXX-XX-XXXXXXXX
            $table->string('cai', 50)->nullable();             // Código CAI (snapshot)
            $table->string('emission_point', 3)->nullable();   // Snapshot del punto de emisión
            $table->date('invoice_date');
            $table->date('cai_expiration_date')->nullable();   // Snapshot del vencimiento

            // ─── Datos del emisor (snapshot) ──────────────
            $table->string('company_name');
            $table->string('company_rtn', 20);
            $table->text('company_address');
            $table->string('company_phone')->nullable();
            $table->string('company_email')->nullable();

            // ─── Datos del receptor (snapshot) ────────────
            $table->string('customer_name');
            $table->string('customer_rtn', 20)->nullable();

            // ─── Totales fiscales (snapshot) ──────────────
            $table->decimal('subtotal', 12, 2);         // Base gravada
            $table->decimal('exempt_total', 12, 2)->default(0); // Total exento
            $table->decimal('taxable_total', 12, 2);    // Total gravado (base)
            $table->decimal('isv', 12, 2);               // ISV 15%
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);

            // ─── Estado y meta ────────────────────────────
            $table->boolean('is_void')->default(false);   // Si la factura fue anulada
            $table->boolean('without_cai')->default(false); // Factura sin CAI
            $table->string('pdf_path')->nullable();        // Ruta al PDF generado

            // ─── Integridad e inmutabilidad ───────────────
            // integrity_hash: SHA-256 de campos fiscales al momento de emisión.
            // Cualquier UPDATE directo en DB rompe el hash — detectable por comando de verificación.
            // emitted_at: marca el momento de emisión. Mientras no sea NULL el Observer bloquea UPDATEs fiscales.
            $table->string('integrity_hash', 64)->nullable();
            $table->timestamp('emitted_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // ─── Índices ──────────────────────────────────
            $table->index(['invoice_date', 'is_void']);
            $table->index(['establishment_id', 'invoice_date']);
            $table->index('sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
