<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Services\Expenses\ExpenseService;
use App\Services\FiscalPeriods\Exceptions\PeriodoFiscalCerradoException;
use App\Services\Purchases\Exceptions\FacturaYaRegistradaException;
use App\Services\Purchases\Exceptions\MontosDocumentoInvalidosException;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Edición de gasto — solo campos descriptivos y fiscales.
 *
 * Los campos estructurales (sucursal, fecha, método de pago, monto, usuario)
 * están bloqueados en ExpenseForm — ver PHPDoc allí. Si hay error real,
 * la corrección correcta es anular el gasto y registrar uno nuevo desde
 * caja, no editarlo.
 *
 * El guardado pasa por ExpenseService::updateFiscalData para que la copia
 * del gasto en el Libro de Compras (Fase 1b) se cree, actualice o anule en
 * la misma transacción.
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

    /**
     * @throws Halt Si el Libro de Compras no admite el cambio (factura
     *              duplicada o período ya declarado); nada se guarda.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Expense $record */
        try {
            return app(ExpenseService::class)->updateFiscalData($record, $data);
        } catch (MontosDocumentoInvalidosException $e) {
            throw ValidationException::withMessages(["data.{$e->field}" => $e->getMessage()]);
        } catch (FacturaYaRegistradaException|PeriodoFiscalCerradoException $e) {
            Notification::make()
                ->title('No se guardaron los cambios')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }
    }
}
