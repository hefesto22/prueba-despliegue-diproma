<?php

namespace App\Filament\Resources\FiscalPeriods;

use App\Filament\Resources\FiscalPeriods\Pages;
use App\Filament\Resources\FiscalPeriods\Tables\FiscalPeriodsTable;
use App\Models\FiscalPeriod;
use App\Services\FiscalPeriods\FiscalPeriodService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Declaraciones ISV — listado de períodos fiscales con estado
 * (abierto / declarado / reabierto) y acciones Declarar/Reabrir.
 *
 * Los períodos no se crean ni editan manualmente desde la UI: se lazy-crean
 * en FiscalPeriodService cuando se emite/consulta una factura del mes, y
 * solo se modifican via las acciones de dominio expuestas en la tabla.
 */
class FiscalPeriodResource extends Resource
{
    protected static ?string $model = FiscalPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $navigationLabel = 'Declaraciones ISV';

    protected static ?string $modelLabel = 'Período Fiscal';

    protected static ?string $pluralModelLabel = 'Declaraciones ISV';

    protected static ?int $navigationSort = 97;

    public static function getNavigationGroup(): ?string
    {
        return 'Administración';
    }

    /**
     * Badge: número de períodos VENCIDOS sin declarar (mes en curso excluido).
     *
     * Usa `countOverdue()` (query pura + memo de request) en vez de
     * `listOverdue()->count()`. Esto evita:
     *   1. Lazy-create en cada render del sidebar (antes `listOverdue` creaba
     *      registros; ahora la poblaicón la hace el scheduler diario).
     *   2. Doble query por render (getNavigationBadge + getNavigationBadgeColor
     *      invocan el mismo método y la memo devuelve el valor cacheado la 2ª vez).
     *
     * La fuente única de verdad sigue siendo el Service — badge y widget
     * consumen los mismos helpers, así que no pueden divergir.
     *
     * NOTA DIP: `app()` es excepción documentada y aceptada en métodos `static`
     * de Filament Resource (`getNavigationBadge`, `getNavigationBadgeColor`).
     * No hay `$this` ni ciclo de vida Livewire donde inyectar, y Filament
     * invoca estos métodos sin pasar argumentos. El resto del panel (Widgets,
     * Pages, Actions, closures) sí usa DI propia — solo este caso puntual
     * queda con service locator por contrato del framework.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = app(FiscalPeriodService::class)->countOverdue();

        return $count > 0 ? (string) $count : null;
    }

    /**
     * NOTA DIP: misma excepción documentada que getNavigationBadge — static
     * sin $this; Filament invoca sin args. El memo del singleton FiscalPeriodService
     * hace que la 2ª llamada en el mismo request no repita la query.
     */
    public static function getNavigationBadgeColor(): ?string
    {
        $count = app(FiscalPeriodService::class)->countOverdue();

        return match (true) {
            $count >= 2 => 'danger',  // 2+ meses sin declarar = atrasado
            $count === 1 => 'warning',
            default => null,
        };
    }

    public static function table(Table $table): Table
    {
        return FiscalPeriodsTable::configure($table);
    }

    /**
     * Eager-load de relaciones consumidas por la tabla (columnas `declaredBy.name`
     * y `reopenedBy.name`, ambas toggleables). Sin esto: N+1 al activar toggles.
     * Se seleccionan solo id + name porque la UI no muestra más campos del user.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['declaredBy:id,name', 'reopenedBy:id,name']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFiscalPeriods::route('/'),
        ];
    }
}
