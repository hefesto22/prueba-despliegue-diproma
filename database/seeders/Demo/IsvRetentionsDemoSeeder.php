<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\IsvRetentionType;
use App\Models\Establishment;
use App\Models\IsvRetentionReceived;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Crea retenciones de ISV recibidas para marzo y abril 2026.
 *
 * Diproma (sujeto pasivo retenido) recibe constancias de retención de:
 *   1. Procesadores de tarjeta (BAC, Atlántida) — Acuerdo 477-2013
 *      Retienen 1.5% sobre el volumen bruto de ventas con tarjeta. Llega como
 *      reporte mensual del banco con un monto consolidado.
 *   2. Estado / instituciones — PCM-051-2011
 *      Cuando Diproma vende a un órgano público (alcaldía, escuela), éste
 *      retiene 12.5% del ISV facturado y lo declara al SAR en nombre de Diproma.
 *   3. Grandes contribuyentes — Acuerdo 215-2010
 *      Cliente clasificado como gran contribuyente retiene parte del ISV al
 *      pagar a un proveedor pequeño/mediano como Diproma.
 *
 * Mix elegido para el demo:
 *   - Marzo 2026: 2 retenciones (1 tarjetas BAC, 1 gran contribuyente)
 *   - Abril 2026: 3 retenciones (1 tarjetas BAC, 1 tarjetas Atlántida, 1 Estado)
 *
 * Esta mezcla cubre los 3 tipos del enum IsvRetentionType para que la sección C
 * del Formulario 201 muestre las 3 casillas con datos reales en los reportes.
 *
 * Atribución:
 *   - created_by = Lourdes (contadora) — las retenciones las registra ella al
 *     recibir las constancias mensuales del banco/cliente.
 *
 * Constraint del observer:
 *   IsvRetentionReceivedObserver bloquea creates si el período fiscal está
 *   cerrado. Por eso ESTE seeder DEBE correr ANTES de FiscalClosureDemoSeeder
 *   (que cierra marzo 2026). El orquestador respeta este orden.
 *
 * Idempotencia: firstOrCreate por (period_year, period_month, retention_type,
 * agent_rtn, document_number) — combinación que en la práctica es única.
 *
 * Pre-requisitos:
 *   - OperationalUsersSeeder (contador con email lenin@diproma.hn)
 *   - CompanySettingSeeder (Matriz)
 */
class IsvRetentionsDemoSeeder extends Seeder
{
    public function run(): void
    {
        // El contador firma las retenciones. Resolvemos por email genérico
        // (no por nombre) para que el seeder no se rompa si el contador
        // cambia en el futuro.
        $contador = User::where('email', 'lenin@diproma.hn')->firstOrFail();
        $matriz = Establishment::where('is_main', true)->firstOrFail();

        $retenciones = [
            // ─── Marzo 2026 ──────────────────────────────────────────────
            [
                'period_year' => 2026,
                'period_month' => 3,
                'retention_type' => IsvRetentionType::TarjetasCreditoDebito,
                'agent_rtn' => '08019995011223',
                'agent_name' => 'Banco de América Central Honduras (BAC)',
                'document_number' => 'BAC-RET-202603-008765',
                'amount' => 487.50,
                'notes' => 'Retención sobre volumen procesado en POS BAC durante marzo 2026 (Acuerdo 477-2013, tasa 1.5%).',
            ],
            [
                'period_year' => 2026,
                'period_month' => 3,
                'retention_type' => IsvRetentionType::Acuerdo215_2010,
                'agent_rtn' => '08019988123456',
                'agent_name' => 'Walmart de México y Centroamérica, S. de R.L.',
                'document_number' => 'WMT-CR-2026-001234',
                'amount' => 925.00,
                'notes' => 'Retención por venta institucional a Walmart (gran contribuyente, Acuerdo 215-2010).',
            ],

            // ─── Abril 2026 ──────────────────────────────────────────────
            [
                'period_year' => 2026,
                'period_month' => 4,
                'retention_type' => IsvRetentionType::TarjetasCreditoDebito,
                'agent_rtn' => '08019995011223',
                'agent_name' => 'Banco de América Central Honduras (BAC)',
                'document_number' => 'BAC-RET-202604-009102',
                'amount' => 612.75,
                'notes' => 'Retención sobre volumen procesado en POS BAC durante abril 2026 (Acuerdo 477-2013, tasa 1.5%).',
            ],
            [
                'period_year' => 2026,
                'period_month' => 4,
                'retention_type' => IsvRetentionType::TarjetasCreditoDebito,
                'agent_rtn' => '08019991234567',
                'agent_name' => 'Banco Atlántida, S.A.',
                'document_number' => 'ATL-RET-202604-000456',
                'amount' => 235.40,
                'notes' => 'Retención sobre volumen procesado en POS Atlántida durante abril 2026 (Acuerdo 477-2013, tasa 1.5%).',
            ],
            [
                'period_year' => 2026,
                'period_month' => 4,
                'retention_type' => IsvRetentionType::VentasEstado,
                'agent_rtn' => '08019910101010',
                'agent_name' => 'Alcaldía Municipal de San Pedro Sula',
                'document_number' => 'AMSPS-RET-2026-00789',
                'amount' => 318.75,
                'notes' => 'Retención por venta de suministros a la Alcaldía SPS (Decreto PCM-051-2011, retención del 12.5% sobre ISV facturado).',
            ],
        ];

        $created = 0;
        foreach ($retenciones as $data) {
            $retention = IsvRetentionReceived::firstOrCreate(
                [
                    'period_year' => $data['period_year'],
                    'period_month' => $data['period_month'],
                    'retention_type' => $data['retention_type']->value,
                    'agent_rtn' => $data['agent_rtn'],
                    'document_number' => $data['document_number'],
                ],
                array_merge($data, [
                    'establishment_id' => $matriz->id,
                    'created_by' => $contador->id,
                ])
            );

            if ($retention->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->command?->info(sprintf(
            'Retenciones ISV demo: %d nuevas (de %d totales) — marzo: 2, abril: 3',
            $created,
            count($retenciones),
        ));
    }
}
