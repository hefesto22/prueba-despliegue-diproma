<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_note_items', function (Blueprint $table) {
            $table->id();

            // ─── Relaciones ───────────────────────────────
            // credit_note_id: cascade — los items no existen sin la NC.
            $table->foreignId('credit_note_id')->constrained()->cascadeOnDelete();

            // sale_item_id: restrict — preserva el vínculo fiscal con la línea
            // de factura original. Sin este vínculo no podemos validar saldo
            // acreditable acumulativo.
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();

            // product_id: restrict — el producto debe seguir existiendo para
            // poder registrar el movimiento de kardex al reversar stock.
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // ─── Cantidad y precios (snapshot) ────────────
            // unit_price se guarda CON ISV incluido, igual patrón que sale_items
            // (es el precio que vio el cliente). tax_type es snapshot del estado
            // fiscal del producto al momento de la venta original.
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->string('tax_type');

            // ─── Desglose fiscal (calculado al emitir la NC) ─
            $table->decimal('subtotal', 12, 2)->default(0);    // base sin ISV
            $table->decimal('isv_amount', 12, 2)->default(0);  // ISV de esta línea
            $table->decimal('total', 12, 2)->default(0);       // total de esta línea

            $table->timestamps();

            // ─── Índices ──────────────────────────────────
            // (credit_note_id, sale_item_id): mostrar items de una NC con su
            //   sale_item origen — query común en impresión y validación.
            // (sale_item_id): crítico para validación acumulativa — buscar
            //   rápido cuánto se ha acreditado de una línea de factura
            //   excluyendo NCs anuladas.
            $table->index(['credit_note_id', 'sale_item_id']);
            $table->index('sale_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_items');
    }
};
