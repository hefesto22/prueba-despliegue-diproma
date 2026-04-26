<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('type');                     // MovementType enum

            $table->integer('quantity');                  // Siempre positivo, el tipo indica dirección
            $table->integer('stock_before');              // Stock antes del movimiento
            $table->integer('stock_after');               // Stock después del movimiento

            // Referencia polimórfica al origen (Purchase, futuro Sale, etc.)
            $table->nullableMorphs('reference');

            $table->text('notes')->nullable();           // Razón del ajuste manual

            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Índices para consultas de kardex
            $table->index(['product_id', 'created_at']); // Kardex por producto (más reciente primero)
            $table->index('type');
            // nullableMorphs() ya crea índice en (reference_type, reference_id)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
