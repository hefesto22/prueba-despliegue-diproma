<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();

            $table->string('sale_number', 20)->unique();

            // Cliente: nullable para consumidor final
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            // Datos inmutables del cliente al momento de la venta (para la factura).
            // customer_name es nullable para permitir ventas sin cliente identificado;
            // el servicio de facturación aplica fallback a "Consumidor Final" en el
            // snapshot de la Invoice. El default se mantiene por si no se pasa el campo.
            $table->string('customer_name')->nullable()->default('Consumidor Final');
            $table->string('customer_rtn', 20)->nullable();

            $table->date('date');
            $table->string('status')->default('pendiente');

            // Descuento global
            $table->string('discount_type')->nullable(); // 'percentage' | 'fixed'
            $table->decimal('discount_value', 10, 2)->nullable(); // valor ingresado
            $table->decimal('discount_amount', 12, 2)->default(0); // monto calculado en L

            // Totales fiscales (después de descuento)
            $table->decimal('subtotal', 12, 2)->default(0);  // base gravada
            $table->decimal('isv', 12, 2)->default(0);        // ISV 15%
            $table->decimal('total', 12, 2)->default(0);      // total final

            $table->text('notes')->nullable();

            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Índices para las queries del POS y reportes
            $table->index(['status', 'date']);
            $table->index(['customer_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
