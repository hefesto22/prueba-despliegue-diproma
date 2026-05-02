<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categorías de equipo (tipo de dispositivo recibido en taller).
 *
 * Tabla separada de `categories` (catálogo de productos) por SRP:
 *   - `categories`         → clasifica productos del catálogo de venta.
 *   - `device_categories`  → clasifica equipos recibidos para reparación
 *                            (Laptop, Consola, Teléfono, Tablet, Impresora...).
 *
 * Ejemplos: Laptop, Desktop, Tablet, Consola, Teléfono, Impresora, Monitor.
 *
 * Por qué `is_active`: se preservan referencias históricas en `repairs` aun
 * cuando una categoría se da de baja del listado nuevo (FK restrictOnDelete).
 *
 * Por qué slug: permite navegación pública desde la URL del QR cuando el
 * cliente consulta su reparación (`/r/{token}` muestra el tipo de equipo
 * usando el label, no el id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_categories', function (Blueprint $table) {
            $table->id();

            $table->string('name', 80);
            $table->string('slug', 80)->unique();

            // Heroicon name (ej: "heroicon-o-computer-desktop"); usado en la UI
            // del listado de reparaciones para identificar el tipo de equipo.
            $table->string('icon', 60)->nullable();

            // Orden de aparición en el dropdown del form de recepción.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->boolean('is_active')->default(true);

            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Índice para el dropdown ordenado de categorías activas.
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_categories');
    }
};
