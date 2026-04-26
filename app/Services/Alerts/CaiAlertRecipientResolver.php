<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Authorization\CustomPermission;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;

/**
 * Resuelve el conjunto único de usuarios que deben recibir alertas del módulo
 * CAI.
 *
 * Punto único de política de destinatarios para los jobs operativos del CAI
 * — SendCaiAlertsJob (alertas preventivas de expiración/agotamiento) y
 * ExecuteCaiFailoverJob (notificación crítica cuando un CAI queda bloqueado
 * sin sucesor pre-registrado). Antes de esta abstracción, ambos jobs tenían
 * copiado el mismo algoritmo palabra por palabra — cualquier cambio de política
 * (añadir un nuevo grupo, cambiar el filtro de activos, etc.) obligaba a
 * editar los dos lugares sin que el compilador lo detectara.
 *
 * Política (inalterable sin autorización expresa del negocio):
 *   1. Usuarios `active()` con permiso `Manage:Cai` vía cualquiera de sus roles.
 *   2. Usuarios `active()` con permiso `Manage:Cai` asignado directamente.
 *   3. Usuarios `active()` con rol super_admin — Shield ya les otorga toda
 *      ability por Gate::before, pero los incluimos explícitamente acá para
 *      no depender de ese comportamiento en el canal de notificaciones
 *      (Notification::send NO pasa por Gate).
 *
 * El resultado se dedupea por `id` y se reindexa con `values()`, de modo que
 * un usuario con rol + permiso directo recibe una única copia.
 *
 * Degradación silenciosa: si el permiso custom aún no existe en DB (seeder no
 * ejecutado o deploy incompleto) retorna colección vacía y emite Log::warning.
 * Los jobs callers interpretan vacío como "sin destinatarios" y loguean su
 * propio warning operacional.
 *
 * Decisión de diseño: `final class` — la política es una sola y la componemos
 * desde los jobs. Si surge una variación legítima se compone con decorador o
 * se inyecta una política diferente, nunca por herencia.
 *
 * Interface omitida intencionalmente: hoy ningún caller la fakea (los tests de
 * los jobs usan Notification::fake que intercepta antes de llegar al resolver).
 * Extraer `ResuelveDestinatariosDeAlertasCai` sería YAGNI — la firma del
 * resolver ya es pequeña y estable. Si aparece un caller que necesite fakearlo
 * sin tocar la DB, extraemos la interface en ese momento.
 */
final class CaiAlertRecipientResolver
{
    /**
     * @return Collection<int, User>
     */
    public function resolve(): Collection
    {
        $permissionName = CustomPermission::ManageCai->value;

        $permission = Permission::where('name', $permissionName)->first();

        if ($permission === null) {
            Log::warning(
                "CaiAlertRecipientResolver: permiso {$permissionName} no existe. "
                .'Correr CustomPermissionsSeeder.'
            );

            return collect();
        }

        // Usuarios con el permiso vía rol.
        $viaRoles = User::active()
            ->whereHas('roles.permissions', fn ($q) => $q->where('name', $permissionName))
            ->get();

        // Usuarios con el permiso asignado directamente.
        $direct = User::active()
            ->whereHas('permissions', fn ($q) => $q->where('name', $permissionName))
            ->get();

        // Super admins — Shield::Gate::before les da true para cualquier
        // ability en el panel, pero el canal de notificaciones no pasa por
        // Gate, así que los incluimos explícitamente.
        $superAdminRole = Utils::getSuperAdminName();
        $superAdmins = User::active()
            ->whereHas('roles', fn ($q) => $q->where('name', $superAdminRole))
            ->get();

        return $viaRoles->concat($direct)->concat($superAdmins)->unique('id')->values();
    }
}
