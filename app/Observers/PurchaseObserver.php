<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Purchase;
use App\Services\FiscalPeriods\FiscalPeriodService;
use Carbon\CarbonImmutable;

/**
 * Observer fiscal del modelo Purchase — garantiza la inmutabilidad de las
 * compras cuyo período ya fue declarado al SAR.
 *
 * Por qué existe:
 *   El Libro de Compras del período es insumo directo de la Sección C del
 *   Formulario 201 (ISV mensual). Una vez presentada la declaración, los
 *   totales del libro son los que la SAR tiene en sus registros. Si después
 *   permitimos editar/crear/borrar compras de ese período, nuestro libro
 *   interno deja de coincidir con lo declarado — problema legal directo
 *   ante cualquier fiscalización (Acuerdo SAR 481-2017).
 *
 *   Antes de ISV.3a, esta regla solo aplicaba a `Invoice` (vía
 *   `assertCanVoidInvoice`). Extenderla a Purchase cierra la deuda simétrica
 *   del dominio fiscal.
 *
 * Reglas cubiertas:
 *   - creating: no permitir registrar compras retroactivas en períodos cerrados.
 *   - updating: si la nueva `date` cae en período cerrado → bloqueo.
 *               También bloqueo si la `date` ORIGINAL está en período cerrado
 *               (evita "escapar" del período editando la fecha para salirse).
 *   - deleting: no permitir borrar compras de períodos cerrados.
 *
 * Escape hatch:
 *   La única forma legítima de corregir una compra en período cerrado es:
 *   1. FiscalPeriodService::reopen() — marca el período como reabierto.
 *   2. Editar/borrar/crear la compra.
 *   3. Re-declarar (FiscalPeriodService::declare) — rectificativa al SAR.
 *
 *   Durante el paso 2 el Observer NO bloquea porque `isOpen()` devuelve true
 *   tras reopen(). Flujo natural, sin bypass ni flags especiales.
 *
 * Módulo no activado:
 *   Si `fiscal_period_start` es NULL, `assertDateIsInOpenPeriod()` retorna
 *   sin bloquear (default allow). Esto mantiene el comportamiento pre-F7.1
 *   intacto para empresas que aún no adoptan el tracking de períodos.
 */
class PurchaseObserver
{
    public function __construct(
        private readonly FiscalPeriodService $periods,
    ) {}

    public function creating(Purchase $purchase): void
    {
        if ($purchase->date === null) {
            // Sin fecha no hay período que verificar — el INSERT mismo fallará
            // por NOT NULL, es suficiente para fail-fast.
            return;
        }

        $this->periods->assertDateIsInOpenPeriod(
            $purchase->date,
            $this->describe($purchase, isNewRecord: true),
        );
    }

    public function updating(Purchase $purchase): void
    {
        // Chequeo la fecha ORIGINAL (valor en DB antes del update). Si la compra
        // vive en un período cerrado, no puede tocarse — da igual cuál sea la
        // nueva fecha propuesta. Esto previene el attack vector de "muevo la
        // fecha a un período abierto, edito el total, devuelvo la fecha".
        $originalDate = $purchase->getOriginal('date');

        if ($originalDate !== null) {
            $this->periods->assertDateIsInOpenPeriod(
                CarbonImmutable::parse($originalDate),
                $this->describe($purchase),
            );
        }

        // También chequeo la nueva fecha si cambió, para impedir que alguien
        // mueva una compra A un período cerrado (ej: editar fecha de una compra
        // recién creada para "inyectarla" retroactivamente).
        if ($purchase->isDirty('date') && $purchase->date !== null) {
            $this->periods->assertDateIsInOpenPeriod(
                $purchase->date,
                $this->describe($purchase),
            );
        }
    }

    public function deleting(Purchase $purchase): void
    {
        if ($purchase->date === null) {
            return;
        }

        $this->periods->assertDateIsInOpenPeriod(
            $purchase->date,
            $this->describe($purchase),
        );
    }

    /**
     * Etiqueta human-readable de la compra para el mensaje de error de la
     * excepción. Prefiero el correlativo interno (purchase_number: "COMP-2026-00001")
     * porque es el que el contador ve en listados y reportes. Si aún no se generó
     * (fase creating muy temprana) fallback al número del proveedor, y por último
     * al ID interno o texto genérico.
     */
    private function describe(Purchase $purchase, bool $isNewRecord = false): string
    {
        $purchaseNumber = $purchase->purchase_number ?? null;

        if ($purchaseNumber !== null && $purchaseNumber !== '') {
            return "la compra {$purchaseNumber}";
        }

        $supplierInvoiceNumber = $purchase->supplier_invoice_number ?? null;

        if ($supplierInvoiceNumber !== null && $supplierInvoiceNumber !== '') {
            return "la compra del proveedor {$supplierInvoiceNumber}";
        }

        if ($isNewRecord || $purchase->id === null) {
            return 'esta compra';
        }

        return "la compra #{$purchase->id}";
    }
}
