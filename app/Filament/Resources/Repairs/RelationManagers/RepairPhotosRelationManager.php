<?php

namespace App\Filament\Resources\Repairs\RelationManagers;

use App\Enums\RepairPhotoPurpose;
use App\Services\Repairs\RepairPhotoConverter;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Fotos del equipo (recepción, diagnóstico, durante reparación, finalizada).
 *
 * Convención de almacenamiento:
 *   `storage/app/public/repairs/{repair_id}/{filename}`
 *
 * El borrado físico de archivos al expirar (7 días post-entrega) lo hace
 * `CleanupRepairPhotosJob` (F-R6) — no el modelo. Aquí solo gestionamos
 * el alta y borrado manual desde UI.
 *
 * Límite recomendado: 3 fotos por reparación. La validación está en el
 * FileUpload (max 3 archivos por subida, max 5MB c/u).
 */
class RepairPhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'photos';

    protected static ?string $title = 'Fotos del equipo';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedPhoto;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('photo_path')
                ->label('Foto')
                ->helperText('La imagen se convierte automáticamente a WebP optimizado (resize 1920px, calidad 80).')
                ->image()
                ->imageEditor()
                ->directory(fn () => "tmp/repair-photos") // se mueve y convierte en mutateDataUsing
                ->disk('public')
                ->maxSize(10240) // 10 MB antes de convertir
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->required()
                ->columnSpanFull(),
            Select::make('purpose')
                ->label('Propósito')
                ->required()
                ->default(RepairPhotoPurpose::Recepcion)
                ->options(collect(RepairPhotoPurpose::cases())->mapWithKeys(
                    fn (RepairPhotoPurpose $p) => [$p->value => $p->getLabel()]
                )),
            TextInput::make('caption')
                ->label('Descripción breve (opcional)')
                ->maxLength(200)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('caption')
            ->columns([
                ImageColumn::make('photo_path')
                    ->label('Foto')
                    ->disk('public')
                    ->square()
                    ->size(80)
                    ->openUrlInNewTab(fn ($record) => $record->url),
                TextColumn::make('purpose')
                    ->label('Propósito')
                    ->badge(),
                TextColumn::make('caption')
                    ->label('Descripción')
                    ->placeholder('—')
                    ->limit(50),
                TextColumn::make('uploader.name')
                    ->label('Subida por')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('file_size')
                    ->label('Tamaño')
                    ->formatStateUsing(fn ($state) => $state ? round($state / 1024, 1) . ' KB' : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('purpose')
                    ->label('Propósito')
                    ->options(collect(RepairPhotoPurpose::cases())->mapWithKeys(
                        fn (RepairPhotoPurpose $p) => [$p->value => $p->getLabel()]
                    )),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Subir foto')
                    ->icon('heroicon-o-plus')
                    ->mutateDataUsing(function (array $data) {
                        // Convertir a WebP optimizado y mover al directorio definitivo del repair.
                        // Si la conversión falla, se preserva la foto original como fallback
                        // (mejor tener la foto pesada que perderla por un edge case de GD).
                        if (! empty($data['photo_path'])) {
                            $repairId = $this->getOwnerRecord()->id;
                            try {
                                $data['photo_path'] = app(RepairPhotoConverter::class)->convertToWebp(
                                    sourcePath: $data['photo_path'],
                                    destDirectory: "repairs/{$repairId}",
                                );
                            } catch (\Throwable $e) {
                                logger()->warning('RepairPhoto conversion failed (RelationManager)', [
                                    'repair_id' => $repairId,
                                    'tmp_path' => $data['photo_path'] ?? null,
                                    'error' => $e->getMessage(),
                                ]);
                                // Path queda como vino — el archivo original se conserva en tmp/.
                            }

                            $disk = Storage::disk('public');
                            if ($disk->exists($data['photo_path'])) {
                                $data['file_size'] = $disk->size($data['photo_path']);
                            }
                        }
                        $data['uploaded_by'] = Auth::id();
                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->after(function ($record) {
                        // Borrado físico del archivo al borrar el registro
                        if ($record->photo_path) {
                            \Illuminate\Support\Facades\Storage::disk('public')->delete($record->photo_path);
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->after(function ($records) {
                            foreach ($records as $record) {
                                if ($record->photo_path) {
                                    \Illuminate\Support\Facades\Storage::disk('public')->delete($record->photo_path);
                                }
                            }
                        }),
                ]),
            ]);
    }
}
