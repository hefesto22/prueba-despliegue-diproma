<?php

namespace App\Http\Controllers;

use App\Models\Repair;
use App\Services\Repairs\RepairPublicTrackingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Vista pública de tracking de una Reparación.
 *
 * URL: /r/{repair:qr_token}
 *
 * El cliente escanea el QR del recibo de cotización y aterriza aquí.
 * Sin login. El `qr_token` UUID es el "secreto compartido" — quien tiene
 * el token puede ver el estado de SU reparación, no de otras.
 *
 * Reparaciones Anuladas se ocultan al público (404 si no está autenticado).
 * Las terminales correctas (Entregada, Rechazada, Abandonada) sí se muestran
 * — sirven como respaldo histórico para el cliente.
 */
class RepairPublicTrackingController extends Controller
{
    public function __construct(
        private readonly RepairPublicTrackingService $tracking,
    ) {}

    public function __invoke(Request $request, Repair $repair): View
    {
        if (! $repair->status->isPublicVisible() && ! Auth::check()) {
            abort(404);
        }

        return view('public.repair-tracking', $this->tracking->buildPayload($repair));
    }
}
