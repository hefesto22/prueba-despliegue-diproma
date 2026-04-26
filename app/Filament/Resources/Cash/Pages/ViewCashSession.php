<?php

namespace App\Filament\Resources\Cash\Pages;

use App\Filament\Resources\Cash\Actions\CloseCashSessionAction;
use App\Filament\Resources\Cash\Actions\PrintCashSessionAction;
use App\Filament\Resources\Cash\Actions\ReconcileCashSessionAction;
use App\Filament\Resources\Cash\Actions\RecordExpenseAction;
use App\Filament\Resources\Cash\CashSessionResource;
use App\Models\CashSession;
use App\Services\Cash\CashBalanceCalculator;
use App\Services\Cash\CashSessionService;
use App\Services\Expenses\ExpenseService;
use Filament\Resources\Pages\ViewRecord;

/**
 * View de una sesión de caja.
 *
 * Acá el contexto tiene un record concreto (`$this->record`). Las actions que
 * requieren sesión abierta (cerrar, registrar gasto) solo aparecen si ESTA
 * sesión específica está abierta — a diferencia de ListCashSessions, que
 * resuelve la sesión abierta de la sucursal del user.
 *
 * El RelationManager de movimientos se monta en C3.5.
 */
class ViewCashSession extends ViewRecord
{
    protected static string $resource = CashSessionResource::class;

    /**
     * Servicios inyectados via boot() — Livewire 3 soporta method injection en
     * boot(). Propiedades protected para que NO se serialicen entre requests
     * (solo las public lo hacen) — el container resuelve fresh cada render.
     */
    protected CashBalanceCalculator $balanceCalculator;

    protected CashSessionService $cashSessions;

    protected ExpenseService $expenses;

    public function boot(
        CashBalanceCalculator $balanceCalculator,
        CashSessionService $cashSessions,
        ExpenseService $expenses,
    ): void {
        $this->balanceCalculator = $balanceCalculator;
        $this->cashSessions = $cashSessions;
        $this->expenses = $expenses;
    }

    /**
     * Título dinámico que comunica el estado de la sesión de un vistazo.
     * Antes era "Ver 3" (default Filament: label de página + recordTitle).
     */
    public function getTitle(): string
    {
        /** @var CashSession $record */
        $record = $this->record;

        return $record->isOpen()
            ? "Sesión #{$record->id} · Abierta"
            : "Sesión #{$record->id} · Cerrada";
    }

    /**
     * Breadcrumb del recurso individual — antes mostraba "3" (recordTitle),
     * ahora muestra "Sesión #3" para mantener consistencia con el título.
     */
    public function getBreadcrumb(): string
    {
        /** @var CashSession $record */
        $record = $this->record;

        return "Sesión #{$record->id}";
    }

    protected function getHeaderActions(): array
    {
        // Resolver para actions sobre sesiones abiertas (cerrar, registrar gasto):
        // historia inmutable — sobre sesiones cerradas estas actions no aplican.
        $openSessionResolver = function (): ?CashSession {
            /** @var CashSession $record */
            $record = $this->record;

            return $record->isOpen() ? $record : null;
        };

        // Resolver para conciliación: solo aplica si la sesión fue auto-cerrada
        // por el sistema y aún espera conteo físico tardío. Acepta el `?CashSession`
        // que Filament inyecta para mantener firma compatible con la versión de
        // la action que vive en row actions de la tabla — acá ignoramos el
        // argumento y usamos `$this->record` (el record de esta Page).
        $reconcileResolver = function (?CashSession $record = null): ?CashSession {
            /** @var CashSession $current */
            $current = $this->record;

            return $current->isClosed() && $current->isPendingReconciliation() ? $current : null;
        };

        return [
            PrintCashSessionAction::make(),
            CloseCashSessionAction::make($openSessionResolver, $this->balanceCalculator, $this->cashSessions),
            ReconcileCashSessionAction::make($reconcileResolver, $this->cashSessions),
            RecordExpenseAction::make($openSessionResolver, $this->expenses),
        ];
    }
}
