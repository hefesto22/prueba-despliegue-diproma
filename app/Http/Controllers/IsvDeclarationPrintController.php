<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IsvMonthlyDeclaration;
use App\Services\FiscalPeriods\IsvDeclarationPrintService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * Vista autenticada de una Declaración ISV Mensual para impresión /
 * "Guardar como PDF".
 *
 * Invokable controller (SRP: una acción única). Delega el armado del payload
 * en `IsvDeclarationPrintService` y solo se ocupa de conectar HTTP con dominio
 * + autorizar. Mismo patrón que `InvoicePrintController`,
 * `CreditNotePrintController`, `CashSessionPrintController`.
 *
 * AUTORIZACIÓN
 * ────────────
 * Reutiliza `FiscalPeriodPolicy@view` via el FiscalPeriod del snapshot. No se
 * crea un `IsvMonthlyDeclarationPolicy` dedicado porque el snapshot no tiene
 * CRUD (es inmutable) y acceso == "puede ver el período". Quien puede ver el
 * período puede reimprimir cualquier snapshot (vigente o supersedido) de ese
 * período — útil para auditorías del contador.
 *
 * La Blade muestra una franja de "DECLARACIÓN REEMPLAZADA" en snapshots
 * supersedidos, de modo que un PDF archivado nunca se confunde con la vigente.
 */
class IsvDeclarationPrintController extends Controller
{
    public function __construct(
        private readonly IsvDeclarationPrintService $printService,
    ) {}

    public function __invoke(IsvMonthlyDeclaration $isvMonthlyDeclaration): View
    {
        Gate::authorize('view', $isvMonthlyDeclaration->fiscalPeriod);

        return view(
            'isv-declarations.print',
            $this->printService->buildPrintPayload($isvMonthlyDeclaration),
        );
    }
}
