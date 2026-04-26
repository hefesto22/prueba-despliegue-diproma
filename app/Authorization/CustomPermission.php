<?php

declare(strict_types=1);

namespace App\Authorization;

/**
 * Enum fuente de verdad de TODOS los custom permissions del sistema.
 *
 * "Custom permissions" = los que Shield NO detecta automáticamente al correr
 * `shield:generate` porque no son métodos estándar del scaffold
 * (viewAny/view/create/update/delete/...). Son acciones de dominio puras:
 * declarar un período, reabrirlo, gestionar CAIs, etc.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * ¿POR QUÉ UN ENUM Y NO STRINGS EN CONFIG?
 * ─────────────────────────────────────────────────────────────────────────
 *   1) Type-safety — `CustomPermission::ManageCai->value` no tiene typos
 *      silenciosos. `'Manage.Cai'` vs `'Manage:Cai'` con strings es invisible
 *      hasta que alguien nota que las notificaciones no llegan en producción.
 *   2) Find-all-references — renombrar un permiso es refactor seguro que el
 *      IDE sigue automáticamente. Con strings sueltos, `grep` es tu único aliado.
 *   3) Single source of truth — Shield UI, seeder, jobs, policies y widgets
 *      leen DEL MISMO enum. Cero sincronización manual entre config y DB.
 *   4) Metadata acoplada — label en español y group viven junto al case, no
 *      en diccionarios paralelos que hay que mantener sincronizados.
 *   5) Iterable — `self::cases()` da todos los permisos en un solo llamado,
 *      útil para el seeder y para el service provider.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * CÓMO AGREGAR UN PERMISO NUEVO
 * ─────────────────────────────────────────────────────────────────────────
 *   1) Agregar un `case` al enum con su label (ES) y group.
 *   2) Correr: `php artisan db:seed --class=CustomPermissionsSeeder`
 *   3) Asignar el permiso al rol correspondiente desde el panel Shield.
 *
 * Eso es todo. No se toca config/filament-shield.php, no se crean seeders
 * nuevos, no se sincroniza nada manualmente.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * FLUJO AUTOMÁTICO
 * ─────────────────────────────────────────────────────────────────────────
 *   - `CustomPermissionServiceProvider::boot()` inyecta `CustomPermission::names()`
 *     en `config('filament-shield.custom_permissions')` al arrancar la app.
 *     Shield entonces descubre los permisos en el UI de edición de roles.
 *   - `CustomPermissionsSeeder` itera `CustomPermission::cases()` y hace
 *     `firstOrCreate` en la tabla `permissions` de Spatie. Idempotente.
 *   - En código de negocio, usar `CustomPermission::X->value` en `can(...)`,
 *     en `where('name', ...)`, y en tests. Nunca strings sueltos.
 */
enum CustomPermission: string
{
    // ─── Períodos fiscales ───────────────────────────────────
    case DeclareFiscalPeriod = 'Declare:FiscalPeriod';
    case ReopenFiscalPeriod = 'Reopen:FiscalPeriod';

    // ─── CAI (Código de Autorización de Impresión SAR) ───────
    case ManageCai = 'Manage:Cai';

    /**
     * Etiqueta en español para UI administrativa (si algún día se quiere
     * mostrar al lado del checkbox en Shield). Hoy Shield muestra el value,
     * pero tener esto ya listo evita rework cuando se internacionalice.
     */
    public function label(): string
    {
        return match ($this) {
            self::DeclareFiscalPeriod => 'Declarar período fiscal',
            self::ReopenFiscalPeriod => 'Reabrir período fiscal (rectificativa)',
            self::ManageCai => 'Gestionar alertas de CAI',
        };
    }

    /**
     * Grupo lógico del permiso — útil para agrupar en el UI de Shield o en
     * la documentación generada. No afecta la autorización.
     */
    public function group(): string
    {
        return match ($this) {
            self::DeclareFiscalPeriod,
            self::ReopenFiscalPeriod => 'Períodos fiscales',
            self::ManageCai => 'CAI / Facturación',
        };
    }

    /**
     * Todos los nombres de permiso como array de strings. Usado por el
     * service provider para poblar el config de Shield y por el seeder para
     * iterar los cases sin acoplarse al enum en SQL.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
