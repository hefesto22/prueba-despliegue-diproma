<?php

namespace App\Http\Controllers;

use App\Models\Repair;
use App\Services\Repairs\RepairQuotationPrintService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Genera la vista imprimible del Recibo Interno de Cotización.
 *
 * Acceso:
 *   - Usuarios autenticados (staff): cualquier reparación, vía Filament Action.
 *   - Cliente final (sin login): solo si trae el `qr_token` correcto en URL.
 *
 * El parámetro de la ruta es `{repair:qr_token}` — el token UUID actúa como
 * "secreto compartido" entre Diproma y el cliente. Sin login pero con el
 * token correcto, el cliente puede ver SU cotización (no la de otros).
 *
 * No expone reparaciones anuladas (RepairStatus::Anulada::isPublicVisible()
 * retorna false). Si llega un acceso a una anulada y el usuario NO está
 * autenticado, se devuelve 404. Si está autenticado, se permite ver
 * (el staff sí puede revisar cotizaciones anuladas).
 */
class RepairQuotationPrintController extends Controller
{
    public function __construct(
        private readonly RepairQuotationPrintService $printer,
    ) {}

    public function __invoke(Request $request, Repair $repair): View
    {
        if (! $repair->status->isPublicVisible() && ! Auth::check()) {
            abort(404);
        }

        $payload = $this->printer->buildPrintPayload($repair);

        return view('printable.repair-quotation', $payload);
    }
}
