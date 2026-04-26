<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->integer('quantity');
            $table->decimal('unit_cost', 12, 2);    // Costo unitario como lo ingresa el usuario (CON ISV para gravados)
            $table->string('tax_type');               // TaxType del producto al momento de la compra
            $table->decimal('subtotal', 12, 2);       // Costo base sin ISV * quantity
            $table->decimal('isv_amount', 12, 2)->default(0); // ISV de esta línea (crédito fiscal)
            $table->decimal('total', 12, 2);          // subtotal + isv_amount = unit_cost * quantity

            // Seriales recibidos en esta compra
            $table->json('serial_numbers')->nullable();

            $table->timestamps();

            // Índices
            $table->index('product_id');
            $table->index(['purchase_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};
