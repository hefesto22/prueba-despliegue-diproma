<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Créditos a favor del cliente (saldo disponible para usar en futuras compras).
 *
 * NO confundir con `credit_notes` (Nota de Crédito SAR tipo 03), que es un
 * documento fiscal que acredita una factura ya emitida. Los CustomerCredits
 * existen ANTES de cualquier factura — caso de uso original: anticipo de
 * reparación cobrado, reparación rechazada/anulada, cliente prefirió
 * convertir el anticipo en crédito en vez de devolución en efectivo.
 *
 * Diseño contable:
 *   - `amount`  = monto original del crédito (inmutable).
 *   - `balance` = saldo restante (decrementa al usarse). lockForUpdate
 *     obligatorio en cada uso para evitar race conditions.
 *   - `expires_at` nullable: la política por defecto es "sin expiración"
 *     pero la columna está lista por si el negocio decide vencimientos.
 *   - `fully_used_at`: timestamp cuando balance llega a 0 (auditable).
 *
 * El FK a `source_repair_id` apunta a la reparación que originó el crédito;
 * se agrega en una migración posterior para evitar referencia circular
 * entre las tablas `customer_credits` y `repairs` durante la creación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_credits', function (Blueprint $table) {
            $table->id();

            // Cliente con el crédito a favor — restrictOnDelete: no se puede
            // borrar un cliente que tiene crédito pendiente sin antes resolverlo.
            $table->foreignId('customer_id')
                ->constrained()
                ->restrictOnDelete();

            // Origen del crédito. Hoy solo "repair_advance"; extensible.
            $table->string('source_type', 40);

            // FK lógico al repair de origen (se agrega en migración separada
            // para romper la dependencia circular con la tabla `repairs`).
            $table->unsignedBigInteger('source_repair_id')->nullable();

            // Sucursal donde se generó (consistente con purchases/sales).
            $table->foreignId('establishment_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Montos
            $table->decimal('amount', 12, 2);   // crédito original (inmutable)
            $table->decimal('balance', 12, 2);  // saldo disponible

            // Vigencia opcional (nullable = no vence)
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('fully_used_at')->nullable();

            $table->text('description')->nullable();

            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // ─── Índices ─────────────────────────────────────────────────
            // Patrón query dominante: "créditos disponibles del cliente X".
            $table->index(['customer_id', 'balance'], 'cust_credits_customer_balance_idx');
            // Búsqueda por origen (ej: "todos los créditos venidos de reparaciones").
            $table->index(['source_type', 'source_repair_id'], 'cust_credits_source_idx');
            // Job de notificación de vencimiento (futuro).
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credits');
    }
};
