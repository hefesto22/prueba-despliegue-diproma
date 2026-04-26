<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CashSession;
use App\Services\Cash\CashSessionPrintService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * Vista autenticada de una sesion de caja para impresion / "Guardar como PDF".
 *
 * Invokable controller (SRP: una accion unica). Delega el armado del payload
 * en CashSessionPrintService y solo se ocupa de conectar HTTP con dominio +
 * autorizar. Mismo patron que InvoicePrintController y CreditNotePrintController.
 *
 * Autorizacion: reutiliza CashSessionPolicy@view. Cualquier usuario con
 * permiso 'View:CashSession' puede imprimir. La hoja de cierre se imprime
 * tipicamente despues del cierre (para firmar), pero tambien puede imprimirse
 * una sesion abierta como "corte parcial" — por eso la Policy decide, no el
 * controller.
 */
class CashSessionPrintController extends Controller
{
    public function __construct(
        private readonly CashSessionPrintService $printService,
    ) {}

    public function __invoke(CashSession $cashSession): View
    {
        Gate::authorize('view', $cashSession);

        return view('cash-sessions.print', $this->printService->buildPrintPayload($cashSession));
    }
}
