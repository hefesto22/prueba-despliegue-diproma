<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\Establishment;
use App\Models\Expense;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Genera gastos contables NO-efectivo para el período histórico.
 *
 * Los gastos en EFECTIVO se generan dentro de HistoricalOperationsSeeder
 * porque requieren caja abierta (ver ExpenseService::register). Este seeder
 * cubre los gastos que NO pasan por el cajón:
 *
 *   - Alquiler mensual del local — pago por transferencia, factura del
 *     propietario con CAI. Marzo y abril 2026.
 *   - Servicios básicos mensuales (luz, agua, internet) — pago por
 *     transferencia, factura del proveedor.
 *   - Mantenimiento extraordinario — ocasional, transferencia/cheque.
 *
 * Por qué NO usar ExpenseService:
 *   - ExpenseService requiere caja abierta SI payment_method = Efectivo.
 *     Los gastos de este seeder son siempre no-efectivo, así que el flow
 *     correcto es crear el Expense directamente — sin CashMovement asociado.
 *   - ExpenseService respeta esta misma regla: si el método no es efectivo,
 *     solo crea el Expense. La diferencia es que aquí salteamos el wrapper
 *     transaccional porque cada gasto es independiente.
 *
 * Atribución: created_by = Carlos (admin) para alquiler/servicios; estos
 * los gestiona él, no Sofía.
 *
 * Fechas:
 *   - Alquiler: 1 de cada mes (marzo y abril).
 *   - Servicios: alrededor del 5 (vencimiento típico de facturas de servicios HN).
 *   - Mantenimiento: ocurrencia esporádica.
 *
 * Idempotencia: usa firstOrCreate por (provider_invoice_number, expense_date).
 *
 * Pre-requisitos:
 *   - OperationalUsersSeeder (Carlos)
 *   - CompanySettingSeeder (Matriz)
 */
class HistoricalExpensesSeeder extends Seeder
{
    public function run(): void
    {
        $carlos = User::where('email', 'carlos.mendoza@diproma.hn')->firstOrFail();
        $matriz = Establishment::where('is_main', true)->firstOrFail();

        $gastos = [
            // ─── Alquiler ────────────────────────────────────────────────
            [
                'expense_date' => '2026-03-01',
                'category' => ExpenseCategory::Servicios,
                'payment_method' => PaymentMethod::Transferencia,
                'amount_total' => 18000.00,
                'isv_amount' => 2347.83,
                'is_isv_deductible' => true,
                'description' => 'Alquiler local Boulevard principal — marzo 2026',
                'provider_name' => 'Inmobiliaria Reyes y Cía.',
                'provider_rtn' => '08019988899900',
                'provider_invoice_number' => '001-001-01-00033421',
                'provider_invoice_cai' => 'CC1122-DD3344-EE5566-FF7788-AA9900-BB1122-001234',
                'provider_invoice_date' => '2026-03-01',
            ],
            [
                'expense_date' => '2026-04-01',
                'category' => ExpenseCategory::Servicios,
                'payment_method' => PaymentMethod::Transferencia,
                'amount_total' => 18000.00,
                'isv_amount' => 2347.83,
                'is_isv_deductible' => true,
                'description' => 'Alquiler local Boulevard principal — abril 2026',
                'provider_name' => 'Inmobiliaria Reyes y Cía.',
                'provider_rtn' => '08019988899900',
                'provider_invoice_number' => '001-001-01-00033587',
                'provider_invoice_cai' => 'CC1122-DD3344-EE5566-FF7788-AA9900-BB1122-001234',
                'provider_invoice_date' => '2026-04-01',
            ],

            // ─── Energía Eléctrica (ENEE) ────────────────────────────────
            [
                'expense_date' => '2026-03-08',
                'category' => ExpenseCategory::Servicios,
                'payment_method' => PaymentMethod::Transferencia,
                'amount_total' => 4250.00,
                'isv_amount' => 554.35,
                'is_isv_deductible' => true,
                'description' => 'Energía eléctrica ENEE — febrero 2026',
                'provider_name' => 'Empresa Nacional de Energía Eléctrica (ENEE)',
                'provider_rtn' => '08019999999900',
                'provider_invoice_number' => '202602-008765432',
                'provider_invoice_cai' => null,
                'provider_invoice_date' => '2026-03-05',
            ],
            [
                'expense_date' => '2026-04-08',
                'category' => ExpenseCategory::Servicios,
                'payment_method' => PaymentMethod::Transferencia,
                'amount_total' => 4580.00,
                'isv_amount' => 597.39,
                'is_isv_deductible' => true,
                'description' => 'Energía eléctrica ENEE — marzo 2026',
                'provider_name' => 'Empresa Nacional de Energía Eléctrica (ENEE)',
                'provider_rtn' => '08019999999900',
                'provider_invoice_number' => '202603-009123456',
                'provider_invoice_cai' => null,
                'provider_invoice_date' => '2026-04-05',
            ],

            // ─── Agua (Aguas de SPS) ─────────────────────────────────────
            [
                'expense_date' => '2026-03-12',
                'category' => ExpenseCategory::Servicios,
                'payment_method' => PaymentMethod::Transferencia,
                'amount_total' => 850.00,
                'isv_amount' => null,
                'is_isv_deductible' => false,
                'description' => 'Agua potable — febrero 2026',
                'provider_name' => 'Aguas de San Pedro',
                'provider_rtn' => '08019988877700',
                'provider_invoice_number' => '202602-1122334',
                'provider_invoice_cai' => null,
                'provider_invoice_date' => '2026-03-10',
            ],
            [
                'expense_date' => '2026-04-12',
                'category' => ExpenseCategory::Servicios,
                'payment_method' => PaymentMethod::Transferencia,
                'amount_total' => 875.00,
                'isv_amount' => null,
                'is_isv_deductible' => false,
                'description' => 'Agua potable — marzo 2026',
                'provider_name' => 'Aguas de San Pedro',
                'provider_rtn' => '08019988877700',
                'provider_invoice_number' => '202603-1124501',
                'provider_invoice_cai' => null,
                'provider_invoice_date' => '2026-04-10',
            ],

            // ─── Internet (Tigo Business) ────────────────────────────────
            [
                'expense_date' => '2026-03-15',
                'category' => ExpenseCategory::Servicios,
                'payment_method' => PaymentMethod::TarjetaCredito,
                'amount_total' => 2800.00,
                'isv_amount' => 365.22,
                'is_isv_deductible' => true,
                'description' => 'Internet fibra empresarial — marzo 2026',
                'provider_name' => 'Tigo Business Honduras',
                'provider_rtn' => '08019966554400',
                'provider_invoice_number' => '001-001-01-00876543',
                'provider_invoice_cai' => 'AA9988-BB7766-CC5544-DD3322-EE1100-FF0011-002201',
                'provider_invoice_date' => '2026-03-15',
            ],
            [
                'expense_date' => '2026-04-15',
                'category' => ExpenseCategory::Servicios,
                'payment_method' => PaymentMethod::TarjetaCredito,
                'amount_total' => 2800.00,
                'isv_amount' => 365.22,
                'is_isv_deductible' => true,
                'description' => 'Internet fibra empresarial — abril 2026',
                'provider_name' => 'Tigo Business Honduras',
                'provider_rtn' => '08019966554400',
                'provider_invoice_number' => '001-001-01-00879221',
                'provider_invoice_cai' => 'AA9988-BB7766-CC5544-DD3322-EE1100-FF0011-002201',
                'provider_invoice_date' => '2026-04-15',
            ],

            // ─── Mantenimiento extraordinario ────────────────────────────
            [
                'expense_date' => '2026-03-20',
                'category' => ExpenseCategory::Mantenimiento,
                'payment_method' => PaymentMethod::Transferencia,
                'amount_total' => 3500.00,
                'isv_amount' => 456.52,
                'is_isv_deductible' => true,
                'description' => 'Mantenimiento aire acondicionado — limpieza y carga de gas',
                'provider_name' => 'Climatec Servicios',
                'provider_rtn' => '08019977711200',
                'provider_invoice_number' => '001-001-01-00012876',
                'provider_invoice_cai' => 'BB2233-CC4455-DD6677-EE8899-FF0011-AA1122-003344',
                'provider_invoice_date' => '2026-03-20',
            ],
            [
                'expense_date' => '2026-04-10',
                'category' => ExpenseCategory::Mantenimiento,
                'payment_method' => PaymentMethod::Cheque,
                'amount_total' => 1850.00,
                'isv_amount' => 241.30,
                'is_isv_deductible' => true,
                'description' => 'Reparación cerradura puerta principal — visita técnica',
                'provider_name' => 'Cerrajería Don Jorge',
                'provider_rtn' => '08019944422100',
                'provider_invoice_number' => '001-001-01-00003121',
                'provider_invoice_cai' => 'DD4455-EE6677-FF8899-AA0011-BB2233-CC4455-005566',
                'provider_invoice_date' => '2026-04-10',
            ],
        ];

        $created = 0;
        foreach ($gastos as $data) {
            $expense = Expense::firstOrCreate(
                [
                    'provider_invoice_number' => $data['provider_invoice_number'],
                    'expense_date' => $data['expense_date'],
                ],
                array_merge($data, [
                    'establishment_id' => $matriz->id,
                    'user_id' => $carlos->id,
                    'created_by' => $carlos->id,
                ])
            );

            if ($expense->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->command?->info(sprintf(
            'Gastos no-efectivo creados: %d nuevos (de %d totales)',
            $created,
            count($gastos),
        ));
    }
}
