<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\CompanySetting;
use Database\Seeders\RolesAndSuperAdminSeeder;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Orquestador del demo de datos históricos realistas.
 *
 * Por qué un orquestador dedicado:
 *   - El orden de ejecución de los seeders demo es CRÍTICO. Cada seeder
 *     asume estado pre-existente del anterior (FKs, autenticación, fiscal_period_start).
 *   - Centralizar el orden en una sola clase evita el bug clásico de "lo
 *     corrí en otro orden y todo se rompió" — el orden está documentado y
 *     validado en un solo lugar.
 *   - Permite invocar el demo completo con UN comando:
 *       php artisan db:seed --class=Database\\Seeders\\Demo\\RealisticHistoricalSeeder
 *
 * ────────────────────────────────────────────────────────────────────────
 * ORDEN DE EJECUCIÓN — CADA PASO TIENE UNA RAZÓN
 * ────────────────────────────────────────────────────────────────────────
 *
 *   0. TruncateOperationalDataSeeder
 *      → Limpia tablas operativas (compras, ventas, facturas, kardex, caja,
 *        expenses, fiscal_periods, customers, suppliers operativos, CAIs).
 *      → Preserva: catálogo (productos, categorías, spec_options) y
 *        configuración (users, roles, company_settings, establishments).
 *      → Razón: garantizar reset limpio sin perder datos de catálogo.
 *
 *   1. OperationalUsersSeeder
 *      → Crea Carlos (admin), Lourdes (contador), Sofía (cajero).
 *      → Pre-requisito: roles ya creados por RolesAndSuperAdminSeeder.
 *      → Razón: todos los demás seeders necesitan estos users como autores.
 *
 *   2. SuppliersDemoSeeder
 *      → 6 activos + 2 inactivos (con RTN); el genérico ya existe via migración.
 *      → Pre-requisito: Carlos (creador).
 *
 *   3. CustomersDemoSeeder
 *      → 8 con RTN + 4 sin RTN.
 *      → Pre-requisito: Sofía (creadora — la cajera registra clientes).
 *
 *   4. CaiRangeDemoSeeder
 *      → CAI vencido histórico (Q4 2025) + CAI activo (range 201-1700,
 *        vigencia ene–dic 2026) para Matriz.
 *      → Pre-requisito: Matriz (CompanySettingSeeder).
 *      → Razón: HistoricalOperationsSeeder consume correlativos al emitir facturas.
 *
 *   5. setFiscalPeriodStart()
 *      → Configura `company_settings.fiscal_period_start = 2026-02-01`.
 *      → Razón: FiscalPeriodService::assertConfigured() exige este valor para
 *        cerrar períodos. Las operaciones históricas con fechas >= 2026-02-01
 *        caen dentro del tracking — el observer auto-crea el FiscalPeriod
 *        correspondiente como abierto y permite la operación.
 *
 *   6. HistoricalOperationsSeeder
 *      → Loop diario febrero 2 → abril 25 (saltando domingos, 3 meses):
 *          · Apertura caja con L. 500
 *          · 4-8 ventas (mix CF + RTN, 5% anuladas vía is_void)
 *          · Compras periódicas (cada 5 días, mix Factura + Recibo Interno)
 *          · Gasto pequeño en efectivo (30% probabilidad)
 *          · Cierre caja con monto exacto
 *      → Mix de payment_method en ventas: efectivo, tarjeta crédito,
 *        tarjeta débito, transferencia (sin cheque por decisión del negocio).
 *      → Pre-requisito: users, suppliers, customers, CAI, productos, fiscal_start.
 *      → Razón: corazón del demo. Genera todas las transacciones operativas.
 *
 *   7. HistoricalExpensesSeeder
 *      → Gastos NO efectivo (alquiler, ENEE, agua, internet, mantenimiento).
 *      → Pre-requisito: Matriz, Carlos.
 *      → Razón: cubre gastos que no requieren caja abierta — flujo del 80% real.
 *
 *   8. IsvRetentionsDemoSeeder
 *      → Retenciones ISV recibidas (BAC, Atlántida, Walmart, Alcaldía SPS).
 *      → Pre-requisito: Lourdes (contadora), Matriz.
 *      → Razón: insumo de la sección C del Formulario 201. Debe existir ANTES
 *        de FiscalClosureDemoSeeder porque el observer bloquea creates en
 *        períodos cerrados.
 *
 *   9. FiscalClosureDemoSeeder
 *      → Cierra DOS períodos fiscales en orden cronológico:
 *          · Febrero 2026 → declarado el 2026-03-10
 *          · Marzo 2026   → declarado el 2026-04-10
 *      → Abril 2026 queda ABIERTO (no se puede declarar antes del 10/05).
 *      → Pre-requisito: TODO lo anterior (calcula totales desde libros + retenciones).
 *      → Razón: muestra el ciclo fiscal completo en el demo con dos meses ya
 *        declarados (snapshots inmutables) y uno abierto (operación viva).
 *
 * ────────────────────────────────────────────────────────────────────────
 * IDEMPOTENCIA Y RESET
 * ────────────────────────────────────────────────────────────────────────
 *   Como el paso 0 trunca tablas operativas, este orquestador SIEMPRE
 *   produce un estado consistente independientemente de lo que hubiera antes.
 *   Re-correrlo regenera el demo desde cero sin necesidad de migrate:fresh.
 *
 *   Catálogo (productos, categorías, spec_options) y configuración
 *   (users, roles, company_settings, establishments) se preservan — solo
 *   se limpian datos transaccionales.
 *
 * ────────────────────────────────────────────────────────────────────────
 * NO INCLUIDO POR DECISIÓN
 * ────────────────────────────────────────────────────────────────────────
 *   - Notas de Crédito / Notas de Débito: el negocio no las usa (decisión
 *     explícita de Mauricio 2026-04-19). El sistema las soporta pero el demo
 *     no las puebla porque no reflejan la operación real.
 *   - Formulario 210 ISV / Retenciones ISR: descartados del alcance.
 *
 * ────────────────────────────────────────────────────────────────────────
 * INVOCACIÓN
 * ────────────────────────────────────────────────────────────────────────
 *   php artisan db:seed --class=Database\\Seeders\\Demo\\RealisticHistoricalSeeder
 *
 * Tiempo aproximado: 60-120 segundos en local (depende de cantidad de ventas
 * generadas — el seed determinístico produce ~400-550 ventas en 3 meses).
 */
class RealisticHistoricalSeeder extends Seeder
{
    /**
     * Fecha de inicio del tracking fiscal del demo.
     *
     * Se elige 2026-02-01 porque:
     *   - Coincide con el primer día del primer mes de operaciones del demo
     *     (HistoricalOperationsSeeder loop arranca el 2026-02-02, con stock
     *     inicial el 2026-02-01).
     *   - Permite que febrero y marzo se puedan cerrar al SAR (ambos vencidos
     *     al día 25 de abril 2026).
     *   - Cualquier registro pre-febrero se trata como "pre-tracking".
     */
    private const FISCAL_PERIOD_START = '2026-02-01';

    public function run(): void
    {
        $this->command?->info('═══════════════════════════════════════════════════════════');
        $this->command?->info(' DEMO DIPROMA — Carga histórica realista (febrero–abril 2026)');
        $this->command?->info('═══════════════════════════════════════════════════════════');

        // Pre-requisito: Shield + roles del dominio.
        // Si el orquestador se corre justo después de `migrate:fresh --seed` los
        // roles no existen aún (DatabaseSeeder no los crea — los gestiona
        // RolesAndSuperAdminSeeder, que a su vez exige que `shield:generate`
        // haya corrido para tener los permisos). Sin esto, OperationalUsersSeeder
        // truena con `RoleDoesNotExist: admin`.
        $this->ensurePrerequisitesOrFail();

        // Paso 0: limpieza de datos operativos previos (preserva catálogo).
        $this->command?->info("\n[0/10] Limpiando datos operativos previos (preservando catálogo)…");
        $this->call(TruncateOperationalDataSeeder::class);

        // Paso 1-4: catálogos base
        $this->command?->info("\n[1/10] Usuarios operativos (Carlos, Lourdes, Sofía)…");
        $this->call(OperationalUsersSeeder::class);

        $this->command?->info("\n[2/10] Proveedores…");
        $this->call(SuppliersDemoSeeder::class);

        $this->command?->info("\n[3/10] Clientes…");
        $this->call(CustomersDemoSeeder::class);

        $this->command?->info("\n[4/10] Rangos CAI (vencido histórico + activo ene–dic 2026)…");
        $this->call(CaiRangeDemoSeeder::class);

        // Paso 5: configuración fiscal
        $this->command?->info("\n[5/10] Configurando fiscal_period_start = " . self::FISCAL_PERIOD_START . '…');
        $this->setFiscalPeriodStart();

        // Paso 6-8: histórico transaccional
        $this->command?->info("\n[6/10] Operaciones diarias (febrero 2 → abril 25)…");
        $this->call(HistoricalOperationsSeeder::class);

        $this->command?->info("\n[7/10] Gastos no-efectivo (alquiler, servicios, mantenimiento)…");
        $this->call(HistoricalExpensesSeeder::class);

        $this->command?->info("\n[8/10] Retenciones ISV recibidas…");
        $this->call(IsvRetentionsDemoSeeder::class);

        // Paso 9: cierre fiscal de febrero y marzo (abril queda abierto)
        $this->command?->info("\n[9/10] Cierre fiscal febrero (10/03) + marzo (10/04) 2026…");
        $this->call(FiscalClosureDemoSeeder::class);

        $this->command?->info("\n[10/10] Demo completo.");

        $this->command?->info("\n═══════════════════════════════════════════════════════════");
        $this->command?->info(' DEMO COMPLETO — DB poblada con datos históricos realistas');
        $this->command?->info('═══════════════════════════════════════════════════════════');
        $this->command?->info(' Estado fiscal:');
        $this->command?->info('   · Febrero 2026  → CERRADO (declarado el 10/03/2026)');
        $this->command?->info('   · Marzo 2026    → CERRADO (declarado el 10/04/2026)');
        $this->command?->info('   · Abril 2026    → ABIERTO (operación viva)');
        $this->command?->info(' Logins disponibles (password: 12345678):');
        $this->command?->info('   · admin@gmail.com               (super_admin)');
        $this->command?->info('   · carlos.mendoza@diproma.hn     (admin)');
        $this->command?->info('   · lenin@diproma.hn              (contador)');
        $this->command?->info('   · sofia.lopez@diproma.hn        (cajero)');
        $this->command?->info('═══════════════════════════════════════════════════════════');
    }

    /**
     * Configura `fiscal_period_start` en la única fila de company_settings.
     *
     * Idempotente: si ya estaba seteado en otra fecha, lo reemplaza por la
     * fecha del demo para garantizar que las reglas fiscales del demo
     * funcionen consistentemente. En producción este valor lo setea Mauricio
     * desde el form de CompanySettings — no debería corrermos este orquestador
     * en producción.
     */
    private function setFiscalPeriodStart(): void
    {
        $company = CompanySetting::current();
        $company->update([
            'fiscal_period_start' => self::FISCAL_PERIOD_START,
        ]);
    }

    /**
     * Verifica que estén creados los pre-requisitos (Shield permisos + roles).
     *
     * Si no hay permisos, Shield no se ha corrido — fail loud con el comando
     * exacto que falta. Si hay permisos pero faltan roles, llama a
     * RolesAndSuperAdminSeeder automáticamente para que el demo sea de un
     * solo comando después de `migrate:fresh --seed && shield:generate`.
     */
    private function ensurePrerequisitesOrFail(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        $permissionCount = Permission::where('guard_name', $guard)->count();
        if ($permissionCount === 0) {
            $msg = 'No hay permisos de Shield en DB. Antes de correr este demo ejecutá:'
                . PHP_EOL . '  php artisan shield:generate --all --panel=admin'
                . PHP_EOL . '  php artisan db:seed --class=Database\\\\Seeders\\\\RolesAndSuperAdminSeeder';
            $this->command?->error($msg);
            throw new \RuntimeException($msg);
        }

        $rolesRequeridos = [
            RolesAndSuperAdminSeeder::ROLE_ADMIN,
            RolesAndSuperAdminSeeder::ROLE_CONTADOR,
            RolesAndSuperAdminSeeder::ROLE_CAJERO,
        ];
        $rolesExistentes = Role::where('guard_name', $guard)
            ->whereIn('name', $rolesRequeridos)
            ->count();

        if ($rolesExistentes < count($rolesRequeridos)) {
            $this->command?->info("\n[0/9] Roles del dominio faltantes — corriendo RolesAndSuperAdminSeeder…");
            $this->call(RolesAndSuperAdminSeeder::class);
        }
    }
}
