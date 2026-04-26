<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\FiscalPeriod;
use App\Models\IsvMonthlyDeclaration;
use App\Models\User;
use App\Services\FiscalPeriods\Exceptions\DeclaracionIsvYaExisteException;
use App\Services\FiscalPeriods\FiscalPeriodService;
use App\Services\FiscalPeriods\IsvMonthlyDeclarationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Cierra los períodos fiscales de febrero y marzo 2026 emitiendo sus
 * respectivas declaraciones ISV mensuales.
 *
 * Por qué este seeder existe:
 *   El demo necesita mostrar el ciclo fiscal completo — no solo facturas y
 *   compras "vivas", sino también períodos YA CERRADOS al SAR para que la UI
 *   muestre la diferencia entre:
 *     - Febrero 2026 → declarado el 2026-03-10 (snapshot inmutable).
 *     - Marzo 2026   → declarado el 2026-04-10 (snapshot inmutable).
 *     - Abril 2026   → abierto (operación normal del día a día).
 *
 * Por qué solo feb y mar (no abril):
 *   Las declaraciones ISV mensuales se presentan hasta el día 10 del mes
 *   siguiente. Hoy (2026-04-25) marzo ya está vencido — el contador puede
 *   declararlo. Abril todavía está corriendo, no se puede declarar aún.
 *   Replicar esta regla en el demo mantiene el realismo.
 *
 * Flujo del cierre por período (delegado a IsvMonthlyDeclarationService::declare):
 *   1. Lock del FiscalPeriod (lockForUpdate).
 *   2. Verifica que NO exista ya un snapshot activo para el período.
 *   3. Recalcula los 12 totales del Formulario 201 desde:
 *        - SalesBookService (libro de ventas SAR del mes)
 *        - PurchaseBookService (libro de compras SAR del mes)
 *        - IsvRetentionReceived (suma de retenciones del mes)
 *        - Saldo del mes previo (para febrero → 0.0; para marzo → saldo
 *          calculado de febrero)
 *   4. Persiste snapshot inmutable en isv_monthly_declarations.
 *   5. Cierra el FiscalPeriod (declared_at = now, declared_by = Lourdes).
 *
 * Atribución:
 *   - declared_by = Lourdes (contadora). Ella es la única autorizada a presentar
 *     declaraciones SAR — coincide con la jerarquía de roles del proyecto.
 *
 * Time-travel:
 *   - now() se fija en cada fecha de presentación (10 del mes siguiente).
 *     Esto deja un timestamp `declared_at` realista en cada snapshot.
 *
 * Auth context:
 *   - HasAuditFields del model IsvMonthlyDeclaration popula `created_by` desde
 *     Auth::id(). Login a Lourdes asegura que `created_by == declared_by_user_id`,
 *     consistente con la realidad operativa.
 *
 * Acuse SIISAR:
 *   - `siisar_acuse_number` se llena con un patrón realista del portal SAR
 *     (formato: SIISAR-YYYYMM-XXXXXXX). Cada mes recibe un acuse distinto.
 *
 * Idempotencia:
 *   - El propio service lanza DeclaracionIsvYaExisteException si ya existe un
 *     snapshot activo. El seeder captura esa excepción y reporta "ya existe"
 *     en vez de fallar — permite re-correr el seeder sin migrate:fresh.
 *
 * Pre-requisitos (orden estricto en el orquestador):
 *   - HistoricalOperationsSeeder (ventas/compras de feb + mar)
 *   - HistoricalExpensesSeeder (gastos no-efectivo)
 *   - IsvRetentionsDemoSeeder (retenciones del mes)
 */
class FiscalClosureDemoSeeder extends Seeder
{
    /**
     * Períodos a cerrar con su fecha de declaración (10 del mes siguiente).
     *
     * Orden: febrero primero, marzo después. El service calcula el saldo
     * previo de cada mes leyendo el snapshot del mes anterior — declarar
     * en orden cronológico garantiza que el saldo de marzo lea correctamente
     * el de febrero.
     */
    private const PERIODOS_A_DECLARAR = [
        [
            'year' => 2026,
            'month' => 2,
            'declaration_date' => '2026-03-10 09:00:00',
            'siisar_acuse' => 'SIISAR-202602-0031847',
            'notes' => 'Declaración ISV mensual febrero 2026 — presentada en plazo legal.',
        ],
        [
            'year' => 2026,
            'month' => 3,
            'declaration_date' => '2026-04-10 09:00:00',
            'siisar_acuse' => 'SIISAR-202603-0048291',
            'notes' => 'Declaración ISV mensual marzo 2026 — presentada en plazo legal.',
        ],
    ];

    public function __construct(
        private readonly FiscalPeriodService $fiscalPeriods,
        private readonly IsvMonthlyDeclarationService $declarations,
    ) {}

    public function run(): void
    {
        // El contador firma la declaración. Resolvemos por email genérico
        // para que el seeder no se rompa si el nombre del contador cambia
        // en el futuro (ej. Lenin → otro). El email es fuente de verdad.
        $contador = User::where('email', 'lenin@diproma.hn')->firstOrFail();

        foreach (self::PERIODOS_A_DECLARAR as $config) {
            $this->declarePeriod($contador, $config);
        }
    }

    /**
     * Cierra un período fiscal específico.
     *
     * @param  array{year:int, month:int, declaration_date:string, siisar_acuse:string, notes:string}  $config
     */
    private function declarePeriod(User $contador, array $config): void
    {
        $declarationDate = CarbonImmutable::parse($config['declaration_date']);

        // Time-travel a fecha realista de presentación.
        CarbonImmutable::setTestNow($declarationDate);
        \Carbon\Carbon::setTestNow($declarationDate);

        try {
            Auth::login($contador);

            // Resolver / crear el período del mes objetivo.
            $period = $this->fiscalPeriods->forDate(
                CarbonImmutable::create($config['year'], $config['month'], 15)
            );

            // Si ya está cerrado (re-run del seeder), reportar y salir.
            $existingActive = IsvMonthlyDeclaration::query()
                ->forFiscalPeriod($period->id)
                ->active()
                ->first();

            if ($existingActive !== null) {
                $this->command?->info(sprintf(
                    '%s %d ya estaba declarado (snapshot ID %d, acuse: %s). Saltando.',
                    $this->monthName($config['month']),
                    $config['year'],
                    $existingActive->id,
                    $existingActive->siisar_acuse_number ?? '—',
                ));
                return;
            }

            // Cerrar el período con su declaración.
            $snapshot = $this->declarations->declare(
                period: $period,
                declaredBy: $contador,
                siisarAcuse: $config['siisar_acuse'],
                notes: $config['notes'],
            );

            $this->command?->info(sprintf(
                '%s %d declarado: snapshot #%d, ventas L. %s, compras L. %s, ISV a pagar L. %s',
                $this->monthName($config['month']),
                $config['year'],
                $snapshot->id,
                number_format((float) $snapshot->ventas_totales, 2),
                number_format((float) $snapshot->compras_totales, 2),
                number_format((float) $snapshot->isv_a_pagar, 2),
            ));
        } catch (DeclaracionIsvYaExisteException $e) {
            // Defensa adicional: si la verificación previa fallara por timing,
            // el service también protege el invariante. Tratamos como idempotente.
            $this->command?->warn(sprintf(
                '%s %d ya tenía declaración activa: %s',
                $this->monthName($config['month']),
                $config['year'],
                $e->getMessage(),
            ));
        } finally {
            Auth::logout();
            CarbonImmutable::setTestNow();
            \Carbon\Carbon::setTestNow();
        }
    }

    private function monthName(int $month): string
    {
        return match ($month) {
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
            default => "Mes-{$month}",
        };
    }
}
