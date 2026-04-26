<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\DocumentType;
use App\Models\CaiRange;
use App\Models\Establishment;
use Illuminate\Database\Seeder;

/**
 * Crea CAIs de demo para soportar el histórico de facturación de feb-abr 2026.
 *
 * Estrategia (post-2026-04-25):
 *   - 1 CAI VENCIDO histórico (oct-dic 2025) — evidencia visual de un CAI
 *     ya cerrado en la UI; uso histórico, no se consume.
 *   - 1 CAI ACTIVO con vigencia ene–dic 2026 — la "ventana" autorizada que
 *     consume el seeder histórico (HistoricalOperationsSeeder) cubriendo
 *     todo el período de demo (febrero, marzo, abril).
 *
 * Por qué el rango es amplio (1500 correlativos):
 *   El histórico cubre 3 meses con ~12 ventas/día y un % con factura SAR.
 *   1500 da margen amplio para que el operador siga trabajando después
 *   del demo sin renovar el CAI hasta diciembre.
 *
 * Formato del prefix (norma SAR): XXX-XXX-XX
 *   - XXX = código de establecimiento (Matriz = '001')
 *   - XXX = punto de emisión (default = '001')
 *   - XX  = tipo de documento (Factura = '01')
 *   → Para Matriz/Factura el prefix es '001-001-01'.
 *
 * El número CAI tiene 37 caracteres con formato hex con guiones que SAR
 * autoriza por bloque. Generamos cadenas representativas (no de SAR real).
 *
 * Idempotente: firstOrCreate por (cai, document_type, establishment_id).
 *
 * Activación:
 *   - Solo el CAI vigente queda is_active=true.
 *   - El vencido queda is_active=false (uso histórico).
 *   - La constraint `uniq_active_cai_per_doc_estab` garantiza un único activo
 *     por (document_type, establishment_id).
 *
 * Requisitos previos:
 *   - CompanySettingSeeder (Matriz).
 */
class CaiRangeDemoSeeder extends Seeder
{
    public function run(): void
    {
        $matriz = Establishment::where('is_main', true)->firstOrFail();

        // ─── CAI 1: vencido histórico (Q4 2025) ──────────────────────────
        // Vigencia: 1 oct 2025 → 31 dic 2025. Queda como evidencia de un
        // CAI ya cerrado, NO se consume en el demo.
        $caiVencido = CaiRange::firstOrCreate(
            [
                'cai' => 'A1B2C3-D4E5F6-A1B2C3-D4E5F6-A1B2C3-D4E5F6-001234',
                'document_type' => DocumentType::Factura->value,
                'establishment_id' => $matriz->id,
            ],
            [
                'authorization_date' => '2025-10-01',
                'expiration_date' => '2025-12-31',
                'prefix' => '001-001-01',
                'range_start' => 1,
                'range_end' => 200,
                'current_number' => 200,
                'is_active' => false,
            ]
        );

        // ─── CAI 2: activo, vigente ene–dic 2026 ─────────────────────────
        // Cubre todo el período del demo (feb-abr 2026) con holgura para
        // continuar operando. Range 201..1700 (1500 correlativos) — más
        // que suficiente para todos los seeders y para que Mauricio siga
        // trabajando hasta fin de año sin renovar.
        // current_number empieza en range_start - 1 = 200 (próximo a usar = 201).
        $caiActivo = CaiRange::firstOrCreate(
            [
                'cai' => 'F7E8D9-C6B5A4-F7E8D9-C6B5A4-F7E8D9-C6B5A4-005678',
                'document_type' => DocumentType::Factura->value,
                'establishment_id' => $matriz->id,
            ],
            [
                'authorization_date' => '2026-01-15',
                'expiration_date' => '2026-12-31',
                'prefix' => '001-001-01',
                'range_start' => 201,
                'range_end' => 1700,
                'current_number' => 200,
                'is_active' => false,
            ]
        );

        // Activar el CAI vigente — usa el método del modelo que respeta el
        // lock y la constraint de unicidad por alcance.
        if (! $caiActivo->is_active) {
            $caiActivo->refresh()->activate();
        }

        $this->command?->info(sprintf(
            'CAIs demo: vencido (rango %s-%s) + activo (rango %s-%s, vence %s)',
            $caiVencido->range_start,
            $caiVencido->range_end,
            $caiActivo->range_start,
            $caiActivo->range_end,
            $caiActivo->expiration_date->format('d/m/Y'),
        ));
    }
}
