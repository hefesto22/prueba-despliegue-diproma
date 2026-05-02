<?php

namespace App\Filament\Widgets;

use App\Enums\RepairStatus;
use App\Filament\Resources\Repairs\RepairResource;
use App\Models\Repair;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Widget del Escritorio para el técnico.
 *
 * Muestra dos grupos de reparaciones que el técnico necesita ver de un vistazo:
 *
 *   1. Asignadas a él en estado activo (Aprobado, EnReparación) — su carga de trabajo.
 *   2. Sin técnico asignado en estado Aprobado — disponibles para tomar.
 *
 * NO muestra reparaciones ListoEntrega (ya las terminó él, espera al cliente).
 * NO muestra terminales (Entregada/Rechazada/Anulada/Abandonada) — ruido.
 *
 * Visible SOLO para el rol `tecnico` para no contaminar el escritorio
 * de otros roles. Admin/super_admin tienen su propia vista global desde
 * el listado de Reparaciones.
 */
class MyRepairsWidget extends TableWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return auth()->user()?->hasRole('tecnico') === true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Mis reparaciones')
            ->description('Reparaciones asignadas a ti en proceso, y pendientes de tomar.')
            ->query(
                Repair::query()
                    ->select([
                        'id', 'repair_number', 'qr_token', 'status', 'received_at',
                        'customer_name', 'customer_phone',
                        'device_brand', 'device_model', 'device_category_id',
                        'technician_id', 'total', 'approved_at',
                    ])
                    ->with([
                        'deviceCategory:id,name,icon',
                    ])
                    ->where(function (Builder $q) {
                        // Mías en estados activos donde tengo trabajo concreto.
                        $q->where(function (Builder $sub) {
                            $sub->where('technician_id', auth()->id())
                                ->whereIn('status', [
                                    RepairStatus::Aprobado->value,
                                    RepairStatus::EnReparacion->value,
                                ]);
                        })
                        // O sin técnico asignado, listas para iniciar.
                        ->orWhere(function (Builder $sub) {
                            $sub->whereNull('technician_id')
                                ->where('status', RepairStatus::Aprobado->value);
                        });
                    })
                    ->orderByRaw("CASE status
                        WHEN 'en_reparacion' THEN 1
                        WHEN 'aprobado' THEN 2
                        ELSE 3 END")
                    ->orderBy('approved_at')
            )
            ->columns([
                TextColumn::make('repair_number')
                    ->label('No.')
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),
                TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->limit(25)
                    ->description(fn (Repair $r) => $r->customer_phone),
                IconColumn::make('deviceCategory.icon')
                    ->label('Tipo')
                    ->icon(fn (Repair $r) => $r->deviceCategory?->icon ?? 'heroicon-o-question-mark-circle')
                    ->tooltip(fn (Repair $r) => $r->deviceCategory?->name),
                TextColumn::make('device_brand')
                    ->label('Equipo')
                    ->formatStateUsing(fn (Repair $r) => trim(($r->device_brand ?? '') . ' ' . ($r->device_model ?? '')))
                    ->limit(30),
                TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL'),
                TextColumn::make('approved_at')
                    ->label('Aprobado')
                    ->since()
                    ->tooltip(fn (Repair $r) => $r->approved_at?->format('d/m/Y H:i'))
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Abrir')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Repair $r) => RepairResource::getUrl('edit', ['record' => $r])),
            ])
            ->emptyStateHeading('Sin reparaciones pendientes')
            ->emptyStateDescription('No tienes reparaciones asignadas en proceso, ni hay ninguna esperando técnico.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated(false);
    }
}
