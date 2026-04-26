<?php

namespace App\Models;

use App\Services\UserHierarchyService;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use RuntimeException;
use Spatie\Permission\Traits\HasRoles;
use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory,
        Notifiable,
        HasRoles,
        HasPanelShield,
        SoftDeletes,
        HasAuditFields,
        LogsActivity;

    /**
     * Email reservado del usuario "sistema" — actor para acciones automatizadas.
     *
     * Lo usan jobs y services que ejecutan operaciones sin un humano detrás
     * (auto-cierre de caja, futuros jobs de expiración, ajustes automáticos)
     * para atribuir correctamente el `user_id` en tablas con FK NOT NULL a users
     * (cash_movements, activity_log, etc.) sin "manchar" la cuenta del operador
     * humano cuyo nombre aparezca por casualidad en el contexto.
     *
     * El registro físico se crea via SystemUserSeeder con:
     *   - is_active = false (no puede entrar al panel)
     *   - sin roles asignados (canAccessPanel lo bloquea por doble seguridad)
     *   - password aleatorio inalcanzable (no recuperable, no login posible)
     */
    public const SYSTEM_EMAIL = 'system@diproma.local';

    /**
     * Cache estática del system user para evitar query repetida en hot paths
     * (ej. job que cierra N sesiones — una sola lookup en vez de N).
     *
     * Se invalida solo dentro del proceso PHP actual; cada request/worker
     * empieza limpio, lo cual es lo correcto: si alguien borrara y recreara
     * el system user en runtime, el siguiente request lo verá.
     */
    private static ?self $systemUserCache = null;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar_url',
        'is_active',
        'default_establishment_id',
        'last_login_at',
        'last_login_ip',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    // ─── Helpers de rol ─────────────────────────────────────

    /**
     * Verificar si el usuario es super admin.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole(\BezhanSalleh\FilamentShield\Support\Utils::getSuperAdminName());
    }

    /**
     * Verificar si el usuario tiene acceso basico al panel.
     */
    public function isPanelUser(): bool
    {
        return $this->hasRole(\BezhanSalleh\FilamentShield\Support\Utils::getPanelUserRoleName());
    }

    /**
     * Check if the user can access the Filament panel.
     *
     * Criterio: el usuario debe estar activo Y tener al menos un rol asignado.
     * Los permisos finos (qué Resource ve, qué acción ejecuta) los resuelven
     * las Policies + Filament Shield basadas en permisos de Spatie — no este
     * método. Hardcodear roles aquí es deuda que se rompe cada vez que se
     * agrega un rol nuevo (admin, cajero, vendedor, contador, etc.).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (!$this->is_active) {
            return false;
        }

        return $this->roles()->exists();
    }

    // ─── Configuracion de Activity Log ───────────────────────

    /**
     * Configure activity log options.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Usuario {$eventName}");
    }

    // ─── Relaciones ──────────────────────────────────────────

    /**
     * Usuarios creados directamente por este usuario.
     */
    public function createdUsers(): HasMany
    {
        return $this->hasMany(User::class, 'created_by');
    }

    /**
     * Sucursal default del usuario — la sucursal donde opera habitualmente.
     *
     * Se usa como "sucursal activa" por los Services que requieren contexto
     * de sucursal (POS, ajustes de inventario, etc.). Resuelta vía
     * EstablishmentResolver para fallar explícitamente si no está configurada.
     */
    public function defaultEstablishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class, 'default_establishment_id');
    }

    /**
     * Sucursal activa del usuario (alias semántico de defaultEstablishment).
     *
     * Retorna null si el usuario no tiene asignada una sucursal. Los callers
     * deben usar EstablishmentResolver::resolveForUser() para garantizar que
     * siempre obtienen una sucursal válida (o una excepción tipada explícita).
     */
    public function activeEstablishment(): ?Establishment
    {
        return $this->defaultEstablishment;
    }

    // ─── Delegacion a UserHierarchyService ───────────────────

    /**
     * Verificar si este usuario es visible para otro usuario.
     */
    public function isVisibleTo(User $viewer): bool
    {
        return app(UserHierarchyService::class)->isVisibleTo($this, $viewer);
    }

    /**
     * Obtener todos los IDs visibles para este usuario.
     */
    public function getVisibleUserIds(): array
    {
        return app(UserHierarchyService::class)->getVisibleUserIds($this);
    }

    /**
     * Invalidar la cache de descendientes.
     */
    public function clearDescendantCache(): void
    {
        app(UserHierarchyService::class)->clearCache($this);
    }

    // ─── Scopes ───────────────────────────────────────────────

    /**
     * Scope: only active users.
     *
     * @param \Illuminate\Database\Eloquent\Builder<User> $query
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: only inactive users.
     *
     * @param \Illuminate\Database\Eloquent\Builder<User> $query
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope: only users visible for the given user.
     * Super admin sees all, others see their branch.
     *
     * @param \Illuminate\Database\Eloquent\Builder<User> $query
     * @param User $user
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->whereIn('id', $user->getVisibleUserIds());
    }

    /**
     * Scope: excluir el usuario "sistema" de listados.
     *
     * Aplicar en cualquier query que muestre users a humanos: listado en
     * Filament, dropdowns de selección de cajero/responsable, reportes "por
     * usuario", etc. El system user es un actor técnico — no tiene sentido
     * que aparezca en una lista que el admin va a leer.
     *
     * @param \Illuminate\Database\Eloquent\Builder<User> $query
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    public function scopeWithoutSystem($query)
    {
        return $query->where('email', '!=', self::SYSTEM_EMAIL);
    }

    // ─── System user (actor de acciones automáticas) ─────────

    /**
     * Localiza el User reservado del sistema.
     *
     * Cacheado por proceso para que un job que cierra N sesiones no haga N
     * lookups. La cache se reinicia automáticamente entre requests/workers.
     *
     * Si el seeder no se ejecutó, lanza excepción ruidosa — falla rápido en
     * vez de continuar registrando movimientos con un user_id inválido o
     * inventarse uno en runtime (que sería bug peor).
     *
     * @throws RuntimeException si el seeder de system user no se ejecutó.
     */
    public static function system(): self
    {
        if (self::$systemUserCache !== null) {
            return self::$systemUserCache;
        }

        $user = self::query()->where('email', self::SYSTEM_EMAIL)->first();

        if ($user === null) {
            throw new RuntimeException(
                'System user no encontrado (email: ' . self::SYSTEM_EMAIL . '). '
                . 'Ejecutá: php artisan db:seed --class=SystemUserSeeder'
            );
        }

        return self::$systemUserCache = $user;
    }

    /**
     * ¿Este registro es el usuario "sistema"?
     *
     * Útil para guards en código de UI (ej. ocultar acciones de edición sobre
     * el system user incluso si por algún bug se filtra al listado).
     */
    public function isSystem(): bool
    {
        return $this->email === self::SYSTEM_EMAIL;
    }

    /**
     * Invalidar la cache estática del system user.
     *
     * Necesario en dos escenarios:
     *   - Tests con `RefreshDatabase`: la BD se trunca entre tests pero la
     *     cache estática sobrevive en el proceso PHP — un test verá el id de
     *     un system user que ya no existe y los INSERT con FK fallarán.
     *   - Producción si por algún motivo el registro se borra y recrea en
     *     runtime (mantenimiento manual, reseed) — el siguiente acceso debe
     *     ver al nuevo, no al cacheado.
     *
     * No es "código solo para tests" — es la única forma honesta de invalidar
     * el cache cuando la realidad de la BD cambia bajo el proceso PHP.
     */
    public static function clearSystemUserCache(): void
    {
        self::$systemUserCache = null;
    }
}
