<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Edición de gasto — solo campos descriptivos y fiscales.
 *
 * Los campos estructurales (sucursal, fecha, método de pago, monto, usuario)
 * están bloqueados en ExpenseForm — ver PHPDoc allí. Si hay error real,
 * la corrección correcta es anular el gasto y registrar uno nuevo desde
 * caja, no editarlo.
 *
 * Sin DeleteAction — los gastos no se eliminan (regla del dominio fiscal).
 */
class EditExpense extends EditRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * Tras editar, regreso al View — el contador suele querer verificar
     * el cambio antes de volver al listado. Mismo criterio que en
     * IsvRetentionsReceived.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
