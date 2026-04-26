<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix: todas las compras al contado (credit_days = 0) que quedaron
 * marcadas como "pendiente" por el default histórico de la migración
 * original se corrigen a "pagada".
 *
 * Motivación: en contado el pago se ejecuta al momento — no existe
 * "pago pendiente". El default 'pendiente' de la migración original
 * aplicaba el mismo estado a todo tipo de compra, lo que distorsionaba
 * el widget de Cuentas por Pagar del Dashboard y el scope pendientesPago().
 *
 * A partir de ahora el hook creating() del Model Purchase se encarga
 * de marcar Pagada automáticamente en contado; esta migración solo
 * corrige el histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        $affected = DB::table('purchases')
            ->where('credit_days', 0)
            ->where('payment_status', 'pendiente')
            ->update(['payment_status' => 'pagada']);

        if ($affected > 0) {
            // Log visible al correr la migración — ayuda a auditar el fix en prod.
            echo "  → Compras al contado corregidas a 'pagada': {$affected}\n";
        }
    }

    public function down(): void
    {
        // No revertimos: el estado anterior era incorrecto (data corruption).
        // Revertir volvería a marcar como "pendiente" compras que sí están
        // pagadas, re-introduciendo el bug. La migración es forward-only.
    }
};
