<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Listado de gastos contables.
 *
 * No expone Create header action — el Resource tampoco registra Create page
 * (ver PHPDoc de ExpenseResource). El gasto nace desde caja.
 */
class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        // Sin CreateAction — gastos nacen desde caja (RecordExpenseAction).
        return [];
    }
}
