<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();

            // Identificación
            $table->string('name');
            $table->string('rtn', 14)->unique();           // RTN obligatorio para crédito fiscal SAR
            $table->string('company_name')->nullable();     // Razón social (si difiere del nombre comercial)

            // Contacto
            $table->string('contact_name')->nullable();     // Persona de contacto
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('phone_secondary', 20)->nullable();

            // Ubicación
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('department')->nullable();       // Departamento de Honduras

            // Comercial
            $table->integer('credit_days')->default(0);     // Días de crédito (0 = contado)
            $table->text('notes')->nullable();

            $table->boolean('is_active')->default(true);

            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Índices de rendimiento
            $table->index('is_active');
            $table->index('city');
            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
