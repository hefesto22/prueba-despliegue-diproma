<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\Invoicing\InvoicePrintService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * Vista autenticada de una factura para impresion / "Guardar como PDF".
 *
 * Invokable controller (una accion unica = SRP). Delegacion completa al
 * InvoicePrintService: el controller solo conecta HTTP con dominio y autoriza.
 *
 * Autorizacion: reutiliza InvoicePolicy@view (generada por Filament Shield).
 * Cualquier usuario con permiso 'view_invoice' puede imprimir.
 */
class InvoicePrintController extends Controller
{
    public function __construct(
        private readonly InvoicePrintService $printService,
    ) {}

    public function __invoke(Invoice $invoice): View
    {
        Gate::authorize('view', $invoice);

        return view('invoices.print', $this->printService->buildPrintPayload($invoice));
    }
}
