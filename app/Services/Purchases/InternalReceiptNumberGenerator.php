<?php

declare(strict_types=1);

namespace App\Services\Purchases;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\Purchases\Exceptions\LimiteReciboInternoDiarioException;
use App\Services\Purchases\Exceptions\TransaccionRequeridaException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Generador del correlativo para Recibos Internos (compras informales sin CAI).
 *
 * Formato: `RI-YYYYMMDD-NNNN` — prefijo estable + fecha de emisión + secuencia
 * diaria de 4 dígitos comenzando en 0001. La secuencia se reinicia cada día
 * calendario. El campo de destino es `purchases.supplier_invoice_number`
 * (lo aprovecha porque para RI el "número del documento del proveedor" es el
 * número interno que nosotros asignamos).
 *
 * ┌─ Garantías ──────────────────────────────────────────────────────────────┐
 * │ 1. Atómico: requiere una transacción activa — si no, lanza               │
 * │    TransaccionRequeridaException (mismo contrato que CorrelativoCen-     │
 * │    tralizado). Si el INSERT del Purchase falla después de generar el     │
 * │    número, el rollback evita "huecos fantasma" en la secuencia diaria.   │
 * │                                                                          │
 * │ 2. Serializado: hace lockForUpdate sobre el proveedor genérico de RI.    │
 * │    Ese registro existe siempre (creado por la migración del módulo RI)   │
 * │    y actúa como semáforo exclusivo de numeración — dos requests          │
 * │    concurrentes pidiendo RI no pueden colisionar porque compiten por la  │
 * │    misma fila. Esta elección evita crear una tabla counters dedicada:    │
 * │    YAGNI hasta que el volumen lo justifique.                             │
 * │                                                                          │
 * │ 3. Resistente a borrados: considera registros soft-deleted al buscar el  │
 * │    máximo del día (withTrashed). Reusar un NNNN que ya se asignó —       │
 * │    aunque la compra se eliminó — es un riesgo de auditoría (dos          │
 * │    documentos distintos con el mismo folio histórico).                   │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Esta clase no persiste el Purchase — solo entrega el número. El llamador
 * (típicamente CreatePurchase / PurchaseService) es quien hace el INSERT
 * dentro de la misma transacción.
 */
class InternalReceiptNumberGenerator
{
    /**
     * Máximo de RIs por día: 4 dígitos → 0001..9999. Operativamente absurdo
     * superar esto en un negocio físico; si llegáramos hay que revisar el
     * proceso (posiblemente el operador está usando RI para documentos que
     * sí tienen CAI).
     */
    private const MAX_POR_DIA = 9999;

    /**
     * Genera el siguiente correlativo para la fecha indicada.
     *
     * @param  CarbonInterface  $fecha  Fecha de emisión del RI (define el segmento YYYYMMDD).
     * @return string  Número formateado: `RI-YYYYMMDD-NNNN`.
     *
     * @throws TransaccionRequeridaException         Si no hay transacción abierta.
     * @throws LimiteReciboInternoDiarioException    Si ya existen 9999 RIs ese día.
     */
    public function next(CarbonInterface $fecha): string
    {
        if (DB::transactionLevel() === 0) {
            throw new TransaccionRequeridaException();
        }

        // Lock serializador: todas las solicitudes de RI compiten por esta fila.
        // El proveedor genérico existe por construcción (migración lo garantiza).
        // Si no existiera, firstOrFail explota inmediatamente — fail-fast.
        $generico = Supplier::query()
            ->generic()
            ->where('name', Supplier::GENERIC_RI_NAME)
            ->lockForUpdate()
            ->firstOrFail();

        $fechaSegmento = $fecha->format('Ymd');
        $prefix = "RI-{$fechaSegmento}-";

        // withTrashed: un RI soft-deleted ya consumió su número — no se reasigna.
        $ultimo = Purchase::withTrashed()
            ->where('supplier_invoice_number', 'like', $prefix.'%')
            ->orderByDesc('supplier_invoice_number')
            ->value('supplier_invoice_number');

        $siguiente = $ultimo
            ? ((int) substr($ultimo, -4)) + 1
            : 1;

        if ($siguiente > self::MAX_POR_DIA) {
            throw new LimiteReciboInternoDiarioException(
                fecha: $fecha->toDateString(),
                maximo: self::MAX_POR_DIA,
            );
        }

        // Referencia muda al modelo locked — mantiene viva la intención del lock
        // para análisis estático / lectores futuros. El lockForUpdate aplica por
        // la fila hasta COMMIT; no depende de usar la variable.
        unset($generico);

        return $prefix.str_pad((string) $siguiente, 4, '0', STR_PAD_LEFT);
    }
}
