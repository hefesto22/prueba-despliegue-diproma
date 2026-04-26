<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix: las compras existentes que heredaron `credit_days > 0` desde el
 * proveedor (por el bug del PurchaseForm previo a 2026-04-25) se reescriben
 * a contado y se marcan como pagadas.
 *
 * Motivación
 * ──────────
 * El form de compra antes heredaba automáticamente `credit_days` desde el
 * proveedor seleccionado. Como el operador no podía corregir el campo
 * (estaba `disabled`) y el módulo de Cuentas por Pagar (registrar pagos
 * parciales, conciliar saldos, antigüedad de saldos, notificaciones de
 * vencimiento) NO está implementado, todas esas compras quedaban como
 * "pendientes de pago" sin forma operativa de marcarlas pagadas. Esto
 * inflaba el dashboard de CxP, generaba alertas falsas de vencimiento y
 * confundía al operador.
 *
 * Decisión de negocio
 * ───────────────────
 * Hasta que CxP se construya completo, todas las compras se registran al
 * contado. La columna `suppliers.credit_days` y la lógica de crédito en el
 * modelo Purchase (hooks, scopes, isOverdue, due_date) se mantienen como
 * base para cuando el módulo se implemente — esta migración solo corrige
 * el histórico y deja la BD coherente con la nueva regla.
 *
 * Forward-only
 * ────────────
 * `down()` no revierte porque el estado anterior era data corruption:
 * compras de contado mal marcadas como crédito pendiente. Revertir
 * volvería a introducir el bug.
 *
 * Idempotente: corre múltiples veces sin efecto adicional (los WHERE
 * solo matchean filas con el bug; tras el fix, el set queda vacío).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Las compras pendientes con crédito heredado: las llevamos a contado
        // pagado, sin fecha de vencimiento. payment_status='pagada' refleja
        // la realidad operativa (al contado el pago se ejecutó al recibir
        // mercancía). due_date=NULL elimina las alertas falsas de vencimiento.
        $affected = DB::table('purchases')
            ->where('credit_days', '>', 0)
            ->update([
                'credit_days'    => 0,
                'due_date'       => null,
                'payment_status' => 'pagada',
                'updated_at'     => now(),
            ]);

        if ($affected > 0) {
            // Log visible al correr la migración — útil para auditar el fix en prod.
            echo "  → Compras con crédito heredado corregidas a contado/pagada: {$affected}\n";
        }
    }

    public function down(): void
    {
        // Forward-only — ver docblock de la migración.
        // Revertir re-introduce data corruption: marca como pendientes-de-crédito
        // compras que se pagaron al contado. No se ofrece down().
    }
};
