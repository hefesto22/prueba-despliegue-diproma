<?php

declare(strict_types=1);

namespace App\Providers;

use App\Authorization\CustomPermission;
use Illuminate\Support\ServiceProvider;

/**
 * Inyecta los custom permissions del enum `CustomPermission` en el config de
 * Filament Shield ANTES de que Shield descubra los permisos para mostrarlos
 * en el UI de edición de roles.
 *
 * ¿Por qué un provider y no escribir el array en config/filament-shield.php?
 *   - Single source of truth: el enum es el único lugar donde se declara un
 *     custom permission. Si alguien agrega un case, Shield lo ve al siguiente
 *     request sin que haya que editar el config manualmente.
 *   - Refactoring: renombrar un case del enum propaga automáticamente. Si el
 *     config tuviera el string hardcoded, quedaría desincronizado.
 *
 * Orden de arranque: register() solo declara bindings (ninguno aquí); boot()
 * corre después de que todos los providers están registrados, así que es
 * seguro tocar config. Shield lee `config('filament-shield.custom_permissions')`
 * dentro de sus propios boot hooks cuando el panel renderiza el UI de roles —
 * para ese momento ya tenemos el array poblado.
 */
class CustomPermissionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        config([
            'filament-shield.custom_permissions' => CustomPermission::names(),
        ]);
    }
}
