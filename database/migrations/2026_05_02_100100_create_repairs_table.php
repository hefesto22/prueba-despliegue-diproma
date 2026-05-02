<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reparaciones — orden de servicio técnico.
 *
 * Una reparación tiene ciclo de vida propio (ver enum RepairStatus):
 *   Recibido → Cotizado → Aprobado → EnReparacion → ListoEntrega → Entregada
 *
 * Al pasar a `Entregada`, RepairDeliveryService crea Sale + Invoice CAI,
 * descuenta stock de piezas internas y registra ingreso en caja.
 * Mientras tanto, `sale_id` e `invoice_id` permanecen nullable.
 *
 * Diseño multi-tenant: `establishment_id` nullable, consistente con sales/
 * purchases (F6b diferido). Cuando aparezca segunda sucursal, el filtro y
 * backfill quedan listos sin migración estructural.
 *
 * Snapshot de cliente (customer_name/phone/rtn) se guarda inmutable porque
 * el dato del cliente puede cambiar luego en `customers` y la cotización
 * impresa entregada al cliente debe coincidir con su recibo físico.
 *
 * `qr_token` (UUID v4) sirve para dos use cases simultáneos:
 *   - URL pública firmada `/r/{qr_token}` (cliente consulta estado + fotos).
 *   - Búsqueda nativa en Filament (cajero escanea con lector USB).
 *
 * `device_password` se cifra a nivel de Eloquent (cast 'encrypted'). Se
 * guarda cuando el cliente entrega contraseña de desbloqueo del equipo;
 * solo el técnico autenticado puede verla.
 *
 * Totales (subtotal, exempt_total, taxable_total, isv, total) son SNAPSHOT
 * vigente — se recalculan cuando cambian los items vía RepairTaxCalculator.
 * Una vez el repair pasa a `Entregada`, los totales quedan congelados (la
 * Sale + Invoice generadas son la fuente fiscal de verdad).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repairs', function (Blueprint $table) {
            $table->id();

            // ─── Identificación ──────────────────────────────────────────
            $table->string('repair_number', 20)->unique(); // REP-2026-00001
            $table->uuid('qr_token')->unique();

            // ─── Sucursal (nullable: F6b diferido) ───────────────────────
            $table->foreignId('establishment_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // ─── Cliente ─────────────────────────────────────────────────
            // customer_id nullable: walk-in sin registro previo está permitido.
            // Si tiene RTN se auto-crea/vincula Customer al recibir.
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            // Snapshot inmutable del cliente al momento de la recepción.
            $table->string('customer_name', 200);
            $table->string('customer_phone', 30);
            $table->string('customer_rtn', 20)->nullable();

            // ─── Equipo recibido ─────────────────────────────────────────
            $table->foreignId('device_category_id')
                ->constrained('device_categories')
                ->restrictOnDelete(); // no se elimina categoría con reparaciones
            $table->string('device_brand', 80);
            $table->string('device_model', 120)->nullable();
            $table->string('device_serial', 120)->nullable();
            // Texto cifrado (cast 'encrypted' en el modelo). Solo técnico ve.
            $table->text('device_password')->nullable();
            // Texto libre describiendo qué reportó el cliente.
            $table->text('reported_issue');
            // Diagnóstico técnico (se llena al pasar a Cotizado).
            $table->text('diagnosis')->nullable();

            // ─── Estado y técnico ────────────────────────────────────────
            $table->string('status', 20)->default('recibido');
            $table->foreignId('technician_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // ─── Timestamps de transición (denormalizados para reportes) ─
            // Se guardan en la fila además del repair_status_logs para evitar
            // joins en queries de "reparaciones recibidas hoy", "promedio de
            // tiempo Cotizado→Aprobado", etc.
            $table->timestamp('received_at');
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('repair_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // ─── Totales fiscales (snapshot vigente de cotización) ───────
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('exempt_total', 12, 2)->default(0);
            $table->decimal('taxable_total', 12, 2)->default(0);
            $table->decimal('isv', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            // ─── Anticipo ────────────────────────────────────────────────
            // Cobrado al pasar a Aprobado (CashMovement::RepairAdvancePayment).
            // Al entregar: factura cobra (total - advance_payment).
            // Al rechazar/anular: usuario decide devolución vs crédito a favor.
            $table->decimal('advance_payment', 12, 2)->default(0);

            // ─── Vínculos generados al entregar (Entregada) ──────────────
            $table->foreignId('sale_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('invoice_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            // Si el anticipo se convirtió en crédito (post rechazo/anulación).
            $table->foreignId('customer_credit_id')
                ->nullable()
                ->constrained('customer_credits')
                ->nullOnDelete();

            // ─── Notas ───────────────────────────────────────────────────
            $table->text('notes')->nullable();           // visible al cliente
            $table->text('internal_notes')->nullable();  // solo staff

            // ─── Auditoría ───────────────────────────────────────────────
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // ─── Índices ─────────────────────────────────────────────────
            // Patrón de query dominante: listado por estado + fecha de recepción.
            $table->index(['status', 'received_at'], 'repairs_status_received_idx');
            // Reparaciones de un técnico activo (vista "Mis reparaciones").
            $table->index(['technician_id', 'status'], 'repairs_tech_status_idx');
            // Reparaciones de un cliente (historial).
            $table->index(['customer_id', 'received_at'], 'repairs_customer_idx');
            // Reparaciones por sucursal (cuando F6b se active).
            $table->index(['establishment_id', 'status'], 'repairs_establishment_idx');
            // Listas para entrega (notificación + abandono automático).
            $table->index('completed_at');
            // Borrado de fotos: index sobre delivered_at para job de cleanup.
            $table->index('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repairs');
    }
};
