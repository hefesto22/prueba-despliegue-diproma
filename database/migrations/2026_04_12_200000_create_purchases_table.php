<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();

            // Identificación
            $table->string('purchase_number', 20)->unique(); // COMP-2026-00001

            // Proveedor
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();

            // Fechas
            $table->date('date');
            $table->date('due_date')->nullable(); // null = contado, calculada = fecha + credit_days

            // Estados
            $table->string('status')->default('borrador');           // PurchaseStatus enum
            $table->string('payment_status')->default('pendiente');  // PaymentStatus enum

            // Totales fiscales (calculados por PurchaseService)
            $table->decimal('subtotal', 12, 2)->default(0);  // Base sin ISV (para contabilidad)
            $table->decimal('isv', 12, 2)->default(0);       // ISV total (crédito fiscal SAR)
            $table->decimal('total', 12, 2)->default(0);     // subtotal + isv = lo que se paga

            // Condiciones del proveedor al momento de la compra
            $table->integer('credit_days')->default(0);

            $table->text('notes')->nullable();

            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Índices de rendimiento
            $table->index(['status', 'date']);
            $table->index(['supplier_id', 'status']);
            $table->index('payment_status');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
