<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Services\CreditNotes\CreditNotePrintService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * Vista autenticada de una Nota de Credito para impresion / "Guardar como PDF".
 *
 * Invokable controller (una accion unica = SRP). Delegacion completa al
 * CreditNotePrintService: el controller solo conecta HTTP con dominio y autoriza.
 *
 * Autorizacion: reutiliza CreditNotePolicy@view (generada por Filament Shield).
 * Cualquier usuario con permiso 'View:CreditNote' puede imprimir.
 *
 * Simetrico a InvoicePrintController. La diferencia es solo el modelo, el
 * service inyectado y la vista renderizada — el contrato HTTP es identico.
 */
class CreditNotePrintController extends Controller
{
    public function __construct(
        private readonly CreditNotePrintService $printService,
    ) {}

    public function __invoke(CreditNote $creditNote): View
    {
        Gate::authorize('view', $creditNote);

        return view('credit-notes.print', $this->printService->buildPrintPayload($creditNote));
    }
}
