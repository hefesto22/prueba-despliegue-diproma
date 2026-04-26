<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\Invoicing\InvoicePrintService;
use Illuminate\Contracts\View\View;

/**
 * Verificacion publica de factura via QR escaneado.
 *
 * Sin middleware auth: el QR debe ser verificable por cualquiera con el
 * codigo — es informacion publica autocontenida del documento fiscal
 * (empresa emisora, CAI, cliente, totales, estado VALIDA/ANULADA).
 *
 * Se busca la factura por integrity_hash (SHA-256 inmutable), NO por id,
 * para que la URL publica no exponga el id secuencial y evite enumeracion
 * trivial de facturas del sistema. Un hash invalido o inexistente retorna
 * 404 directo (firstOrFail).
 */
class InvoiceVerificationController extends Controller
{
    public function __construct(
        private readonly InvoicePrintService $printService,
    ) {}

    public function __invoke(string $hash): View
    {
        $invoice = Invoice::where('integrity_hash', $hash)->firstOrFail();

        return view('invoices.verify', $this->printService->buildPrintPayload($invoice));
    }
}
