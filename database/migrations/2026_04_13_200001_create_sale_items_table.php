<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2);   // Precio CON ISV (como lo ve el cliente)
            $table->string('tax_type');               // TaxType enum

            // Desglose fiscal (calculado al procesar)
            $table->decimal('subtotal', 12, 2)->default(0);    // base sin ISV
            $table->decimal('isv_amount', 12, 2)->default(0);  // ISV de esta línea
            $table->decimal('total', 12, 2)->default(0);       // total de esta línea

            $table->timestamps();

            // Índice compuesto para buscar items por venta
            $table->index(['sale_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
