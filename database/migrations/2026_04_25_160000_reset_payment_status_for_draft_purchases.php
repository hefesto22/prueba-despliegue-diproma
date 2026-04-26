<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix: las compras en estado Borrador con payment_status='pagada' que
 * quedaron así por el hook `creating` previo a 2026-04-25 vuelven a
 * payment_status='pendiente' — el estado correcto para un Borrador.
 *
 * Motivación
 * ──────────
 * Hasta esta migración, el modelo Purchase marcaba payment_status=Pagada
 * automáticamente en `creating()` cuando credit_days era 0 (contado). Eso
 * generaba una inconsistencia visual e incoherente con el dominio: una
 * compra en Borrador estaba marcada como Pagada aunque todavía no se
 * hubiera ejecutado (no afectó stock, no actualizó costo promedio, no es
 * operación real). El operador veía:
 *   Estado: Borrador
 *   Pago: Pagada   ← prematuro, contradictorio
 *
 * La regla nueva: payment_status se resuelve en
 * PurchaseService::confirm() — no en `creating`. Una compra en Borrador
 * SIEMPRE tiene payment_status=Pendiente; al confirmarse, si es contado
 * pasa a Pagada en la misma transacción donde se afecta el stock.
 *
 * Qué hace este fix
 * ─────────────────
 * UPDATE purchases SET payment_status = 'pendiente'
 *   WHERE status = 'borrador'
 *     AND payment_status = 'pagada'
 *
 * Las confirmadas y anuladas se mantienen — su payment_status sí está
 * resuelto correctamente (Pagada en confirmadas de contado, lo que tenían
 * al momento de anular en anuladas).
 *
 * Idempotente: si no hay borradores con payment_status='pagada', sale en 0.
 *
 * Forward-only: revertir reintroduce la incoherencia visual del bug previo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $afectados = DB::table('purchases')
            ->where('status', 'borrador')
            ->where('payment_status', 'pagada')
            ->update([
                'payment_status' => 'pendiente',
                'updated_at'     => now(),
            ]);

        if ($afectados > 0) {
            echo "  → Borradores con payment_status='pagada' reseteados a 'pendiente': {$afectados}\n";
        } else {
            echo "  → No hay borradores con payment_status incoherente para corregir.\n";
        }
    }

    public function down(): void
    {
        // Forward-only — ver docblock de la migración.
    }
};
