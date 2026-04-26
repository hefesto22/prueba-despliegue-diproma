<?php

namespace App\Livewire;

use App\Models\Establishment;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Badge del topbar Filament v4 que muestra la sucursal activa del usuario
 * y permite cambiarla via modal nativo de Filament.
 *
 * Se monta vía PanelsRenderHook::TOPBAR_END en AdminPanelProvider.
 *
 * F6a.5 — Escritura: actualiza users.default_establishment_id.
 * Los Services resuelven la sucursal via EstablishmentResolver, que lee este
 * campo con prioridad sobre el fallback matriz. No existe storage en sesión
 * (Opción A elegida): un cambio persiste entre dispositivos/sesiones, lo que
 * coincide con el modelo de trabajo de Diproma (cajero fijo por sucursal).
 *
 * Después del cambio emitimos un redirect al Referer para refrescar cualquier
 * listado que ya filtrará por sucursal (Libros SAR en F6a.6/7). Si el usuario
 * está en una página sin filtros de sucursal, el reload es costo mínimo
 * comparado con mostrar datos stale de otra sucursal.
 */
class EstablishmentSwitcher extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /**
     * Filament Action que actúa como badge (label = sucursal activa) Y como
     * trigger del modal de cambio. El atributo ->outlined()->size('sm')
     * renderiza un pill compacto que encaja en el topbar.
     *
     * La lista de sucursales disponibles se consulta en cada render — una
     * query barata (~1-10 filas). Ocultamos el switcher si no hay ninguna
     * sucursal activa (caso edge de onboarding incompleto).
     */
    public function switchAction(): Action
    {
        $user = auth()->user();
        $active = $user?->activeEstablishment();

        // Orden: matriz primero, luego alfabético. Así el selector tiene la
        // sucursal más común en primer lugar sin un índice dedicado en DB.
        $establishments = Establishment::query()
            ->where('is_active', true)
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get(['id', 'name', 'is_main']);

        $hasChoice = $establishments->count() > 1;

        return Action::make('switchEstablishment')
            ->label($active?->name ?? 'Sin sucursal')
            ->icon('heroicon-o-building-storefront')
            ->color($active ? 'gray' : 'warning')
            ->size('sm')
            ->outlined()
            // Si no hay sucursales activas del todo, no mostrar nada:
            // el admin debe configurarlas antes de que el topbar muestre algo.
            ->visible($establishments->isNotEmpty())
            // Si hay exactamente 1, el badge sigue visible (el cajero ve la
            // sucursal) pero no hay nada que cambiar — deshabilitar el click
            // y explicar por qué con tooltip.
            ->disabled(! $hasChoice)
            ->tooltip($hasChoice
                ? 'Cambiar sucursal activa'
                : 'Solo hay una sucursal configurada')
            ->form([
                Select::make('establishment_id')
                    ->label('Sucursal activa')
                    ->helperText('El kardex, facturas y reportes nuevos usarán esta sucursal por defecto.')
                    ->options($establishments->pluck('name', 'id'))
                    ->default($active?->id)
                    ->required()
                    ->native(false)
                    ->searchable(),
            ])
            ->modalHeading('Cambiar sucursal activa')
            ->modalIcon('heroicon-o-building-storefront')
            ->modalSubmitActionLabel('Cambiar')
            ->action(function (array $data) use ($user) {
                // Guardia defensiva: el auth middleware de Filament ya garantiza
                // $user != null dentro del panel, pero no confiamos en un único
                // choke-point para operaciones de escritura.
                if (! $user) {
                    return;
                }

                $targetId = (int) $data['establishment_id'];

                // No-op si eligió la misma — evita notificación confusa y un
                // redirect innecesario.
                if ($user->default_establishment_id === $targetId) {
                    return;
                }

                $target = Establishment::find($targetId);
                if (! $target) {
                    Notification::make()
                        ->title('Sucursal no encontrada')
                        ->body('La sucursal seleccionada ya no existe.')
                        ->danger()
                        ->send();

                    return;
                }

                $user->update(['default_establishment_id' => $target->id]);

                Notification::make()
                    ->title('Sucursal cambiada')
                    ->body("Ahora estás trabajando en: {$target->name}")
                    ->success()
                    ->send();

                // Refresca la página para que cualquier listado ya filtrado
                // por sucursal (Libros SAR en F6a.6/7) re-consulte con el
                // nuevo contexto. Fallback a dashboard si no hay Referer.
                $this->redirect(request()->header('Referer') ?? url('/admin'));
            });
    }

    public function render(): View
    {
        return view('livewire.establishment-switcher');
    }
}
