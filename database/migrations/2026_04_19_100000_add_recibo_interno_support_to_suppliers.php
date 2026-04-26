<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soporte para Recibo Interno (compras informales sin CAI).
 *
 * Cambios:
 *   1. Columna `is_generic` boolean — marca proveedores genéricos que no se
 *      pueden eliminar ni editar como operativos normales. Hoy solo aplica
 *      al proveedor "Varios / Sin identificar" usado en Recibos Internos;
 *      futuro: podría aplicar a "Empleados" (caja chica), "Gastos varios", etc.
 *
 *   2. `rtn` pasa a nullable — el proveedor genérico no tiene RTN. El UNIQUE
 *      se mantiene y en MySQL/PostgreSQL los NULL no entran al índice único,
 *      así que no hay colisión entre múltiples genéricos futuros.
 *
 *   3. Data: inserta el proveedor genérico "Varios / Sin identificar". Lo hago
 *      acá en vez de en un Seeder porque es data de referencia que el sistema
 *      requiere para funcionar (sin este registro el flujo de Recibo Interno
 *      no opera). Incluirlo en la migración garantiza que esté presente en
 *      cualquier entorno sin depender de recordar correr un seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('is_generic')
                ->default(false)
                ->after('is_active')
                ->comment('Proveedor genérico del sistema (no eliminable). Usado para RI.');

            $table->index('is_generic');
        });

        // rtn a nullable para soportar proveedores genéricos sin RTN.
        // En Laravel 11+ el change() funciona sin doctrine/dbal.
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('rtn', 14)->nullable()->change();
        });

        // Insert idempotente del proveedor genérico. updateOrInsert por si
        // alguna reversión parcial dejó el registro — evita duplicados y
        // permite re-correr la migración en dev sin limpiar a mano.
        DB::table('suppliers')->updateOrInsert(
            ['name' => 'Varios / Sin identificar', 'is_generic' => true],
            [
                'rtn' => null,
                'company_name' => null,
                'contact_name' => null,
                'email' => null,
                'phone' => null,
                'phone_secondary' => null,
                'address' => null,
                'city' => null,
                'department' => null,
                'credit_days' => 0,
                'notes' => 'Proveedor genérico del sistema — se usa automáticamente '
                    . 'al registrar compras informales (Recibo Interno, sin CAI). '
                    . 'No eliminar: los recibos internos históricos lo referencian.',
                'is_active' => true,
                'is_generic' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        // Elimino el proveedor genérico antes de revertir la columna.
        DB::table('suppliers')
            ->where('is_generic', true)
            ->where('name', 'Varios / Sin identificar')
            ->delete();

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropIndex(['is_generic']);
            $table->dropColumn('is_generic');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('rtn', 14)->nullable(false)->change();
        });
    }
};
