<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Authorization\CustomPermission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Asigna roles y permisos del dominio Diproma + repara la asignación inicial
 * de super_admin que `shield:super-admin` hace al primer user existente.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * FORMATO DE PERMISOS DE SHIELD EN ESTE PROYECTO
 * ─────────────────────────────────────────────────────────────────────────
 * config/filament-shield.php define:
 *   - separator: ':'
 *   - case:      'pascal'
 *
 * Por eso los nombres de permisos generados por Shield son del tipo:
 *   - Resources: View:Sale, ViewAny:Sale, Create:Purchase, Update:Invoice, ...
 *   - Pages:     View:PointOfSale, View:DeclaracionIsvMensual, View:FiscalBooks
 *   - Widgets:   View:CaiStatusWidget, View:SalesChart, ...
 *   - Seguridad: View:Role, Create:Role, View:Permission (los que admin NO toca)
 *
 * Y los CustomPermission del enum ya respetan el mismo formato:
 *   - Declare:FiscalPeriod, Reopen:FiscalPeriod, Manage:Cai
 *
 * Esto es importante porque cualquier cambio en config/filament-shield.php
 * (separator → '_' o case → 'snake') rompería los matchers de aquí. Si Mauricio
 * decide cambiarlo en el futuro, las constantes ACTION_* y SUFFIX_* concentran
 * los strings hardcodeados — un find/replace ahí basta.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * RESPONSABILIDADES POR ROL
 * ─────────────────────────────────────────────────────────────────────────
 *   - super_admin → Mauricio. Pasa por Gate::before, no necesita permisos.
 *   - admin       → todo EXCEPTO :Role y :Permission (gestión de seguridad).
 *   - contador    → lectura completa + acciones fiscales (declarar/reabrir/CRUD
 *                   de períodos y retenciones ISV).
 *   - cajero      → POS + caja + ventas + facturas + clientes + ver productos.
 *
 * Idempotente por diseño: roles/users via firstOrCreate, syncRoles/syncPermissions
 * reemplazan limpio en cada corrida, cache de Spatie se purga al final.
 *
 * REQUISITO: correr DESPUÉS de `php artisan shield:generate --all --panel=admin`.
 */
class RolesAndSuperAdminSeeder extends Seeder
{
    /**
     * Nombres de roles — fuente de verdad. Cualquier código que compare contra
     * estos roles debe leer de aquí, no hardcodear strings.
     */
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_CONTADOR = 'contador';
    public const ROLE_CAJERO = 'cajero';
    public const ROLE_TECNICO = 'tecnico';

    /**
     * Datos del super admin humano — se ubica/crea por email para que re-correr
     * el seeder no resetee password ni name si Mauricio los cambió desde el panel.
     *
     * NOTA SEGURIDAD: el password default es débil a propósito por requerimiento
     * de Mauricio para entorno de demo local. ANTES de cualquier deploy a un
     * servidor accesible (staging/producción), cambiar este valor a un password
     * fuerte (12+ caracteres, mayúsculas, minúsculas, números, símbolos) o mejor
     * aún rotar el password directamente desde el panel y dejar el seeder solo
     * para crear el usuario inicial.
     */
    /**
     * Email del super_admin — público porque otros seeders (DemoUsersSeeder)
     * necesitan ubicarlo para encadenar `created_by`. Mantenerlo privado
     * obligaría a duplicar el string, lo que rompería al cambiar el dueño
     * del sistema en el futuro.
     */
    public const SUPER_ADMIN_EMAIL = 'admin@gmail.com';
    private const SUPER_ADMIN_NAME = 'Administrador Diproma';
    private const SUPER_ADMIN_DEFAULT_PASSWORD = '12345678';

    /**
     * Acciones de Shield (lado izquierdo del separador, siempre Pascal).
     * Centralizadas para que un cambio de naming sea una sola edición.
     */
    private const ACTION_VIEW = 'View';
    private const ACTION_VIEW_ANY = 'ViewAny';
    private const ACTION_CREATE = 'Create';
    private const ACTION_UPDATE = 'Update';
    private const ACTION_DELETE = 'Delete';

    /**
     * Sufijos de seguridad que admin NO debe tener (reservados a super_admin).
     * Incluye también el RolePolicy generado por Shield (`:Role` cubre todas
     * las acciones sobre el resource Role del propio Shield).
     */
    private const SUFFIX_ROLE = ':Role';
    private const SUFFIX_PERMISSION = ':Permission';

    public function run(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        $allPermissionNames = Permission::where('guard_name', $guard)->pluck('name')->all();

        // Pre-check: si Shield no se corrió, los permisos no existen — fallar
        // ruidoso es mejor que asignar un set vacío silenciosamente.
        if (count($allPermissionNames) === 0) {
            $this->command?->error(
                'No hay permisos en DB. Corré primero: php artisan shield:generate --all --panel=admin'
            );

            return;
        }

        // 1. Crear roles del dominio (o ubicarlos si ya existen).
        $superAdminRole = Role::firstOrCreate(['name' => self::ROLE_SUPER_ADMIN, 'guard_name' => $guard]);
        $adminRole = Role::firstOrCreate(['name' => self::ROLE_ADMIN, 'guard_name' => $guard]);
        $contadorRole = Role::firstOrCreate(['name' => self::ROLE_CONTADOR, 'guard_name' => $guard]);
        $cajeroRole = Role::firstOrCreate(['name' => self::ROLE_CAJERO, 'guard_name' => $guard]);
        $tecnicoRole = Role::firstOrCreate(['name' => self::ROLE_TECNICO, 'guard_name' => $guard]);

        // 2. Crear/ubicar al super admin humano.
        //    Password en plano: el cast 'password' => 'hashed' del modelo lo
        //    procesa al guardar — NO usar Hash::make() (doble hash → login roto).
        $mauricio = User::firstOrCreate(
            ['email' => self::SUPER_ADMIN_EMAIL],
            [
                'name' => self::SUPER_ADMIN_NAME,
                'password' => self::SUPER_ADMIN_DEFAULT_PASSWORD,
                'is_active' => true,
            ]
        );

        // Si Mauricio ya existía pero estaba inactivo (caso edge: alguien lo
        // desactivó manualmente), lo reactivamos — un super_admin desactivado
        // no puede entrar al panel y deja el sistema sin admin operativo.
        if (! $mauricio->is_active) {
            $mauricio->update(['is_active' => true]);
        }

        // 3. Quitar super_admin del system user (corrección de la asignación
        //    automática que hace `shield:super-admin` al primer user existente).
        $systemUser = User::where('email', User::SYSTEM_EMAIL)->first();
        if ($systemUser !== null) {
            // syncRoles([]) en vez de removeRole para limpiar TODOS los roles
            // que Shield haya podido asignar (super_admin, panel_user). El
            // system user nunca debe tener roles — canAccessPanel lo bloquea
            // porque is_active=false, pero sin roles es defense in depth.
            $systemUser->syncRoles([]);
        }

        // 4. Defense-in-depth: si alguien más (corrida anterior, shield:super-admin
        //    manual, panel) tiene super_admin, se lo quitamos. El sistema debe
        //    tener exactamente UN super_admin (admin@gmail.com) para que la
        //    auditoría de quién puede tocar roles sea trivial. Esto se ejecuta
        //    ANTES de asignar super_admin al user correcto — si el user correcto
        //    ya tenía el rol, no lo perdemos porque luego se lo re-asignamos.
        $usersConSuperAdmin = User::role(self::ROLE_SUPER_ADMIN)
            ->where('email', '!=', $mauricio->email)
            ->get();
        foreach ($usersConSuperAdmin as $other) {
            $other->removeRole(self::ROLE_SUPER_ADMIN);
        }

        // 5. Asignar super_admin al user humano correcto. syncRoles reemplaza
        //    cualquier rol previo — si re-corremos el seeder con cambios, queda
        //    limpio.
        $mauricio->syncRoles([self::ROLE_SUPER_ADMIN]);

        // 6. Calcular y asignar permisos por rol.
        $adminRole->syncPermissions($this->permisosAdmin($allPermissionNames));
        $contadorRole->syncPermissions($this->permisosContador($allPermissionNames));
        $cajeroRole->syncPermissions($this->permisosCajero($allPermissionNames));
        $tecnicoRole->syncPermissions($this->permisosTecnico($allPermissionNames));

        // super_admin no necesita permisos asignados — el `Gate::before` que
        // Shield instala lo deja pasar todo. Asignar permisos explícitos sería
        // redundante y crearía una segunda fuente de verdad para un mismo rol.

        // Limpiar cache de Spatie para que los cambios se vean en el panel
        // sin reiniciar el servidor.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Reportar resultado de forma legible — útil para verificar que el
        // refresh de la DB salió bien sin tener que abrir tinker.
        $this->command?->info(sprintf(
            'Roles configurados: super_admin=%s | admin=%d permisos | contador=%d permisos | cajero=%d permisos | tecnico=%d permisos (total disponibles: %d)',
            $mauricio->email,
            $adminRole->permissions()->count(),
            $contadorRole->permissions()->count(),
            $cajeroRole->permissions()->count(),
            $tecnicoRole->permissions()->count(),
            count($allPermissionNames),
        ));
    }

    // ─── Cálculo de permisos por rol ────────────────────────────────────

    /**
     * Admin = TODO el sistema EXCEPTO manejo de roles y permisos.
     *
     * El admin humano de Diproma necesita poder hacer todo lo operativo
     * (productos, ventas, compras, caja, fiscal, gastos, etc.) pero NO crear
     * roles ni cambiar permisos — ese privilegio queda solo para super_admin.
     *
     * Filtro: excluir cualquier permiso cuyo subject sea `Role` o `Permission`.
     * Cubre View:Role, ViewAny:Role, Create:Role, Update:Role, Delete:Role,
     * y los equivalentes de Permission. También cubre Restore/ForceDelete si
     * Shield los generó porque todos terminan igual (`:Role` / `:Permission`).
     *
     * @param  array<int, string>  $allPermissionNames
     * @return array<int, string>
     */
    private function permisosAdmin(array $allPermissionNames): array
    {
        return array_values(array_filter(
            $allPermissionNames,
            fn (string $name): bool => ! $this->esPermisoDeSeguridad($name),
        ));
    }

    /**
     * Contador = lectura focalizada en datos fiscales + control de períodos.
     *
     * El contador (Lenin) es responsable de presentar declaraciones SAR y
     * mantener limpios los libros fiscales. NO ve módulos operativos del
     * cajero (POS, caja, movimientos de efectivo) ni del admin (catálogo
     * de categorías/spec_options, configuración de empresa, sucursales,
     * usuarios). Su panel está intencionalmente reducido para que solo
     * vea lo que su trabajo requiere.
     *
     * Estrategia: lista explícita de permisos esperados, intersecada con
     * los que realmente existen en DB. Más restrictivo que el "todo View"
     * anterior — un dev nuevo entiende exactamente qué puede el contador
     * leyendo este array.
     *
     * RESPONSABILIDADES OPERATIVAS:
     *   - Validar registros antes de declarar (lectura de compras/ventas/facturas)
     *   - Verificar RTN/CAI de proveedores y clientes (libros fiscales)
     *   - Conciliar inventario contra movimientos (kardex en lectura)
     *   - Auditar cambios al sistema (Activity Log en lectura)
     *   - Registrar retenciones ISV recibidas (CRUD)
     *   - Crear/cerrar/reabrir períodos fiscales (CRUD)
     *   - Declarar al SAR vía formulario 201 (custom permission)
     *
     * NO ACCEDE A:
     *   - POS, Cash Sessions, Cash Movements (operación del cajero)
     *   - Categorías, Spec Options (catálogo del admin)
     *   - Establecimientos, Configuración de Empresa (admin)
     *   - Usuarios, Roles, Permisos (super_admin/admin)
     *
     * @param  array<int, string>  $allPermissionNames
     * @return array<int, string>
     */
    private function permisosContador(array $allPermissionNames): array
    {
        $deseados = [];

        // ── Resources en lectura ───────────────────────────────────────────
        // El contador audita pero no ejecuta operaciones — todo lo siguiente
        // es estrictamente ViewAny + View. Las acciones de modificación
        // (anular factura, confirmar compra) las hace cajero/admin.

        // Compras: para verificar libro de compras antes de declarar.
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('Purchase', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
            ]),
        );

        // Proveedores: necesita RTN y CAI para validar libro de compras.
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('Supplier', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
            ]),
        );

        // Ventas: para verificar libro de ventas antes de declarar.
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('Sale', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
            ]),
        );

        // Facturas: para validar correcta emisión SAR.
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('Invoice', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
            ]),
        );

        // Clientes: necesita RTN para validar libro de ventas.
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('Customer', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
            ]),
        );

        // Productos: validar costos y stock al cierre fiscal (NO modificar).
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('Product', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
            ]),
        );

        // Kardex: conciliar movimientos de inventario contra compras/ventas.
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('InventoryMovement', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
            ]),
        );

        // Gastos: revisar deducibilidad antes de cerrar período.
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('Expense', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
            ]),
        );

        // CAIs: validar vigencia y rangos disponibles.
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('CaiRange', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
            ]),
        );

        // Activity Log: audit trail para revisar quién registró qué.
        // Útil cuando el contador detecta una compra mal capturada y
        // necesita identificar quién la registró para corregir el flujo.
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('ActivityLog', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
            ]),
        );

        // ── Resources con CRUD completo (responsabilidad fiscal) ───────────

        // Períodos fiscales: el contador los gestiona end-to-end (crea cuando
        // arranca un mes nuevo, cierra al declarar, reabre para rectificativas).
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('FiscalPeriod', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
                self::ACTION_CREATE,
                self::ACTION_UPDATE,
                self::ACTION_DELETE,
            ]),
        );

        // Retenciones ISV recibidas: input directo de la declaración mensual.
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('IsvRetentionReceived', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
                self::ACTION_CREATE,
                self::ACTION_UPDATE,
                self::ACTION_DELETE,
            ]),
        );

        // ── Páginas (Filament las nombra View:NombrePage) ──────────────────

        // Declaración ISV mensual: la pantalla de su acción principal.
        $deseados[] = 'View:DeclaracionIsvMensual';

        // Libros fiscales (Libro de Compras + Libro de Ventas SAR).
        $deseados[] = 'View:FiscalBooks';

        // Reporte mensual de gastos para validar deducibilidad de ISR.
        $deseados[] = 'View:ReporteGastosMensual';

        // ── Custom permissions del dominio fiscal ──────────────────────────
        // Vienen del enum CustomPermission, no del generador de Shield.
        // Manage:Cai es operativo (alertas de vencimiento) — admin lo maneja.

        foreach (CustomPermission::cases() as $custom) {
            if ($custom === CustomPermission::DeclareFiscalPeriod
                || $custom === CustomPermission::ReopenFiscalPeriod) {
                $deseados[] = $custom->value;
            }
        }

        return $this->soloLosQueExisten($deseados, $allPermissionNames);
    }

    /**
     * Cajero = POS + caja + ventas + clientes + facturas + ver productos.
     *
     * El cajero es operador de mostrador. Necesita:
     *   - Página POS (corazón del día a día).
     *   - Caja: abrir/cerrar sesión, registrar movimientos en efectivo.
     *   - Ventas: crear, ver, anular (delete = anular en este dominio).
     *   - Facturas: emitir, ver, anular.
     *   - Clientes: crear/editar al vuelo desde POS.
     *   - Productos: solo ver (NO modificar precios ni stock manualmente).
     *   - Categorías: solo ver (las elige al filtrar en POS).
     *   - Inventario: ver kardex (NO crear movimientos manuales — eso descuadra).
     *   - Gastos pequeños del día (papelería, café, viáticos chicos).
     *
     * NO accede a: compras, proveedores, retenciones ISV, períodos fiscales,
     * usuarios, settings, CAI, libros, declaraciones, activity log.
     *
     * Estrategia: lista explícita de permisos esperados, intersecada con los
     * que realmente existen en DB. Más legible que keyword matching y deja
     * claro QUÉ puede hacer el cajero al leer el array.
     *
     * @param  array<int, string>  $allPermissionNames
     * @return array<int, string>
     */
    private function permisosCajero(array $allPermissionNames): array
    {
        $deseados = [];

        // Página POS — Filament la nombra `View:PointOfSale` por convención.
        $deseados[] = 'View:PointOfSale';

        // Ventas: ver, crear, anular (delete).
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('Sale', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
                self::ACTION_CREATE,
                self::ACTION_DELETE,
            ]),
        );

        // Clientes: ver y crear/editar desde POS (sin delete — no debe borrar
        // historial de clientes).
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('Customer', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
                self::ACTION_CREATE,
                self::ACTION_UPDATE,
            ]),
        );

        // Productos: solo lectura (precios y stock los gestiona admin).
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('Product', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
            ]),
        );

        // Categorías: solo lectura.
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('Category', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
            ]),
        );

        // Sesiones de caja: ver, abrir (create), cerrar (update).
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('CashSession', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
                self::ACTION_CREATE,
                self::ACTION_UPDATE,
            ]),
        );

        // Movimientos de caja: ver y crear (entrega de cambio, retiros, etc.).
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('CashMovement', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
                self::ACTION_CREATE,
            ]),
        );

        // Facturas: emitir contra venta, ver, anular.
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('Invoice', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
                self::ACTION_CREATE,
                self::ACTION_DELETE,
            ]),
        );

        // Inventario (kardex): solo lectura.
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('InventoryMovement', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
            ]),
        );

        // Gastos pequeños del día.
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('Expense', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
                self::ACTION_CREATE,
            ]),
        );

        // Reparaciones: el cajero recibe equipos (alta) y registra entregas.
        // No aprueba/rechaza/inicia reparación — eso es del técnico.
        // Las acciones de transición no son permisos Shield separados; las
        // cubre Update:Repair, y los Filament Actions las gatean en runtime
        // según el estado actual.
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('Repair', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
                self::ACTION_CREATE,
                self::ACTION_UPDATE,
            ]),
        );

        // DeviceCategory: solo lectura para alimentar el dropdown del form.
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('DeviceCategory', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
            ]),
        );

        // CustomerCredit: solo lectura para verificar saldos a favor del cliente.
        // El uso del crédito (consumirlo en una nueva venta) lo hace el sistema
        // automáticamente; el cajero no edita balances manualmente.
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('CustomerCredit', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
            ]),
        );

        return $this->soloLosQueExisten($deseados, $allPermissionNames);
    }

    /**
     * Técnico = el especialista que repara los equipos.
     *
     * RESPONSABILIDADES OPERATIVAS:
     *   - Recibir equipos (con cajero o solo) — crear reparaciones.
     *   - Hacer diagnóstico técnico tras inspeccionar.
     *   - Cotizar (agregar líneas: honorarios + piezas externas/inventario).
     *   - Iniciar reparación, agregar piezas adicionales si surgen, marcar como
     *     completada (esto dispara notificación a admin/cajero para llamar al cliente).
     *   - Subir fotos del equipo durante todo el ciclo.
     *
     * NO HACE (queda para cajero/admin):
     *   - Cobrar anticipo en caja (el dinero lo recibe el cajero).
     *   - Entregar al cliente / emitir factura (eso lo hace cajero al recibir el dinero).
     *   - Anular reparaciones (acción administrativa del admin).
     *
     * SIDEBAR DEL TÉCNICO: solo ve "Reparaciones" — decisión de UX confirmada
     * por Mauricio (2026-05-02). No le mostramos Customer/Product/Category/
     * InventoryMovement como Resources separados aunque los necesite usar
     * indirectamente desde el form de Repair (autocomplete/búsqueda).
     *
     * Por qué los selects siguen funcionando sin permisos `ViewAny:*`:
     *   El form de Repair usa `Customer::query()` y `Product::query()` directos
     *   en `getSearchResultsUsing`. Esas queries NO pasan por la Policy del
     *   Resource — solo por el constraint del modelo. El usuario nunca abre
     *   el Resource Customer/Product, solo los consume desde el flujo de
     *   reparación. La auto-creación de Customer en CreateRepair tampoco
     *   requiere permisos Shield: usa `Customer::firstOrCreate()` directo.
     *
     * @param  array<int, string>  $allPermissionNames
     * @return array<int, string>
     */
    private function permisosTecnico(array $allPermissionNames): array
    {
        $deseados = [];

        // Reparaciones: el ÚNICO Resource que ve en su sidebar.
        // Ver, crear (recepción de equipo), actualizar (diagnóstico, items,
        // transiciones de estado). Delete NO — solo admin elimina.
        $deseados = array_merge(
            $deseados,
            $this->buildResourcePermissions('Repair', [
                self::ACTION_VIEW_ANY,
                self::ACTION_VIEW,
                self::ACTION_CREATE,
                self::ACTION_UPDATE,
            ]),
        );

        return $this->soloLosQueExisten($deseados, $allPermissionNames);
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * Construye los nombres de permiso para un Resource y un set de acciones.
     *
     * Ejemplo: ('Sale', ['View', 'Create']) → ['View:Sale', 'Create:Sale']
     *
     * @param  array<int, string>  $actions
     * @return array<int, string>
     */
    private function buildResourcePermissions(string $resource, array $actions): array
    {
        return array_map(
            fn (string $action): string => "{$action}:{$resource}",
            $actions,
        );
    }

    /**
     * Filtra una lista deseada contra los permisos que existen realmente en DB.
     * Defensivo: si Shield no generó alguno (Resource excluido o renombrado),
     * el seeder no truena al asignar permisos inexistentes.
     *
     * @param  array<int, string>  $deseados
     * @param  array<int, string>  $existentes
     * @return array<int, string>
     */
    private function soloLosQueExisten(array $deseados, array $existentes): array
    {
        return array_values(array_unique(array_intersect($deseados, $existentes)));
    }

    /**
     * ¿Es un permiso de gestión de seguridad (roles o permisos)?
     * Reservado a super_admin — admin no debe poder modificar quién hace qué.
     */
    private function esPermisoDeSeguridad(string $name): bool
    {
        return str_ends_with($name, self::SUFFIX_ROLE)
            || str_ends_with($name, self::SUFFIX_PERMISSION);
    }

    /**
     * ¿Es un permiso de lectura (View:* o ViewAny:*)?
     * Usado por contador para tomar todo lo legible de un saque.
     */
    private function esPermisoDeLectura(string $name): bool
    {
        return str_starts_with($name, self::ACTION_VIEW.':')
            || str_starts_with($name, self::ACTION_VIEW_ANY.':');
    }
}
