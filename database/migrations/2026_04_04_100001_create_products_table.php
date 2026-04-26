<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // Identificación
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku')->unique();
            $table->text('description')->nullable();

            // Clasificación
            $table->foreignId('category_id')
                ->constrained('categories')
                ->restrictOnDelete();

            $table->string('brand')->nullable();
            $table->string('model')->nullable();

            // Condición y clasificación fiscal
            $table->string('condition')->default('new');     // ProductCondition enum
            $table->string('tax_type')->default('gravado_15'); // TaxType enum

            // Precios (sin ISV)
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->decimal('sale_price', 12, 2)->default(0);

            // Inventario
            $table->integer('stock')->default(0);
            $table->integer('min_stock')->default(0);

            // Especificaciones técnicas (JSON flexible para distintos tipos de producto)
            $table->json('specs')->nullable();

            // Serial numbers para equipos individuales (JSON array)
            $table->json('serial_numbers')->nullable();

            // Imagen principal
            $table->string('image_path')->nullable();

            $table->boolean('is_active')->default(true);

            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Índices de rendimiento
            $table->index('is_active');
            $table->index('condition');
            $table->index('tax_type');
            $table->index('brand');
            $table->index(['category_id', 'is_active']);
            $table->index('stock');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
