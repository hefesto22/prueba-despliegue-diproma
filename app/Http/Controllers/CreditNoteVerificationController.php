<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Services\CreditNotes\CreditNotePrintService;
use Illuminate\Contracts\View\View;

/**
 * Verificacion publica de Nota de Credito via QR escaneado.
 *
 * Sin middleware auth: el QR debe ser verificable por cualquiera con el
 * codigo — es informacion publica autocontenida del documento fiscal
 * (empresa emisora, CAI, cliente, totales, razon, estado VALIDA/ANULADA,
 * referencia a la factura origen).
 *
 * Se busca la NC por integrity_hash (SHA-256 inmutable), NO por id, para
 * que la URL publica no exponga el id secuencial y evite enumeracion
 * trivial de documentos del sistema. Un hash invalido o inexistente retorna
 * 404 directo (firstOrFail). La validacion de formato (64 hex chars) la
 * hace el regex de la ruta antes de tocar la BD.
 *
 * Simetrico a InvoiceVerificationController.
 */
class CreditNoteVerificationController extends Controller
{
    public function __construct(
        private readonly CreditNotePrintService $printService,
    ) {}

    public function __invoke(string $hash): View
    {
        $creditNote = CreditNote::where('integrity_hash', $hash)->firstOrFail();

        return view('credit-notes.verify', $this->printService->buildPrintPayload($creditNote));
    }
}
