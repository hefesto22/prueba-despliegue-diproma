<?php

namespace App\Filament\Resources\Repairs\RelationManagers;

use App\Enums\RepairItemSource;
use App\Filament\Resources\Repairs\Schemas\RepairItemSchema;
use App\Services\Repairs\RepairQuotationService;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Líneas de cotización de la reparación.
 *
 * Cada CRUD de línea pasa por `RepairQuotationService`, que:
 *   - resuelve `tax_type` desde `source` + `condition`/`product_id`,
 *   - persiste el item con sus totales pre-calculados,
 *   - recalcula los totales del Repair padre (subtotal/exempt_total/
 *     taxable_total/isv/total).
 *
 * Los campos del form viven en `RepairItemSchema` (compartidos con el
 * modal "Marcar como Cotizado" de `RepairTransitionActions`): `live()`
 * en `source` y `condition` refresca los campos visibles según el tipo
 * de línea (mano de obra → solo precio; pieza externa → precio +
 * nueva/usada + proveedor; pieza inventario → selector de producto).
 */
class RepairItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Cotización';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedListBullet;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema(RepairItemSchema::components()),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                TextColumn::make('source')
                    ->label('Tipo')
                    ->badge(),
                TextColumn::make('description')
                    ->label('Descripción')
                    ->wrap()
                    ->limit(50),
                TextColumn::make('condition')
                    ->label('Cond.')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('quantity')
                    ->label('Cant.')
                    ->numeric(2),
                TextColumn::make('unit_price')
                    ->label('P. Unit.')
                    ->money('HNL'),
                TextColumn::make('tax_type')
                    ->label('Fiscal')
                    ->badge()
                    ->color(fn ($state) => match ($state?->value ?? $state) {
                        'gravado_15' => 'warning',
                        'exento' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money('HNL'),
                TextColumn::make('isv_amount')
                    ->label('ISV')
                    ->money('HNL'),
                TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->weight('bold'),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Tipo')
                    ->options(collect(RepairItemSource::cases())->mapWithKeys(
                        fn (RepairItemSource $s) => [$s->value => $s->getLabel()]
                    )),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Agregar línea')
                    ->icon('heroicon-o-plus')
                    ->using(function (array $data, RepairQuotationService $service) {
                        $data = $this->normalizeData($data);
                        return $service->addItem($this->getOwnerRecord(), $data);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(function ($record, array $data, RepairQuotationService $service) {
                        $data = $this->normalizeData($data);
                        return $service->updateItem($record, $data);
                    }),
                DeleteAction::make()
                    ->using(function ($record, RepairQuotationService $service) {
                        $service->removeItem($record);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Normalizar el array del form antes de pasarlo al service.
     * Delegado a RepairItemSchema::normalize() — misma conversión que
     * usa el modal "Marcar como Cotizado" (una sola fuente de verdad).
     */
    private function normalizeData(array $data): array
    {
        return RepairItemSchema::normalize($data);
    }
}
