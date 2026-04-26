<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Crea proveedores de demo realistas para datos históricos.
 *
 * Mix:
 *   - 6 proveedores activos con RTN (todos contado mientras CxP no esté implementado).
 *   - 2 proveedores inactivos (para listados con filtros).
 *   - El proveedor genérico "Varios / Sin identificar" YA existe (lo creó la
 *     migración 2026_04_19_add_recibo_interno_support_to_suppliers). NO se
 *     duplica aquí — el seeder lo respeta y no lo modifica.
 *
 * Nota sobre `credit_days`: se mantiene en 0 para todos los proveedores demo
 * porque el módulo de Cuentas por Pagar (crédito a proveedores) está pendiente
 * de implementación. Cuando se construya CxP, restaurar los valores históricos
 * (El Sol 30, Centro 15, Norte 45, Mayorista 30) que reflejaban condiciones
 * comerciales reales del giro.
 *
 * Atribución:
 *   - created_by = Carlos (admin) — los proveedores los gestiona el admin
 *     operativo, no Mauricio super_admin. Esto deja trazabilidad realista
 *     en el activity log.
 *
 * Idempotente: firstOrCreate por RTN para los con RTN; por nombre para los
 * que no tienen.
 *
 * Requisitos previos:
 *   - OperationalUsersSeeder (provee Carlos como `created_by`).
 *   - Migración de Recibo Interno (provee el genérico).
 */
class SuppliersDemoSeeder extends Seeder
{
    public function run(): void
    {
        $carlos = User::where('email', 'carlos.mendoza@diproma.hn')->firstOrFail();

        $proveedores = [
            // ─── Activos con RTN ──────────────────────────────────────────
            [
                'name' => 'Distribuidora El Sol',
                'rtn' => '08019998765432',
                'company_name' => 'Distribuidora El Sol, S. de R.L.',
                'contact_name' => 'Roberto Pérez',
                'email' => 'ventas@elsol.hn',
                'phone' => '2550-1100',
                'address' => 'Bo. Suyapa, 5ta Avenida',
                'city' => 'San Pedro Sula',
                'department' => 'Cortés',
                'credit_days' => 0, // Histórico: 30 — restaurar al implementar CxP
                'is_active' => true,
            ],
            [
                'name' => 'Importaciones Centro',
                'rtn' => '08019987654321',
                'company_name' => 'Importaciones Centro, S.A.',
                'contact_name' => 'María Sánchez',
                'email' => 'pedidos@importcentro.hn',
                'phone' => '2553-2200',
                'address' => 'Col. Trejo, calle principal',
                'city' => 'San Pedro Sula',
                'department' => 'Cortés',
                'credit_days' => 0, // Histórico: 15 — restaurar al implementar CxP
                'is_active' => true,
            ],
            [
                'name' => 'Tecno Suministros',
                'rtn' => '05019912345678',
                'company_name' => 'Tecno Suministros HN, S. de R.L.',
                'contact_name' => 'Luis Fonseca',
                'email' => 'cuentas@tecnosuministros.hn',
                'phone' => '2225-4400',
                'address' => 'Bo. La Granja, 8va calle',
                'city' => 'Tegucigalpa',
                'department' => 'Francisco Morazán',
                'credit_days' => 0,
                'is_active' => true,
            ],
            [
                'name' => 'Comercial El Norte',
                'rtn' => '08019956781234',
                'company_name' => 'Comercial El Norte, S. de R.L.',
                'contact_name' => 'Doña Marta Reyes',
                'email' => 'admin@comercialnorte.hn',
                'phone' => '2557-7700',
                'address' => 'Bo. Río de Piedras',
                'city' => 'San Pedro Sula',
                'department' => 'Cortés',
                'credit_days' => 0, // Histórico: 45 — restaurar al implementar CxP
                'is_active' => true,
            ],
            [
                'name' => 'Mayorista Central',
                'rtn' => '08019934567890',
                'company_name' => 'Mayorista Central, S.A. de C.V.',
                'contact_name' => 'Ing. Carlos Núñez',
                'email' => 'ordenes@mayoristacentral.hn',
                'phone' => '2558-8800',
                'address' => 'Zona industrial, bloque B',
                'city' => 'San Pedro Sula',
                'department' => 'Cortés',
                'credit_days' => 0, // Histórico: 30 — restaurar al implementar CxP
                'is_active' => true,
            ],
            [
                'name' => 'Suministros del Valle',
                'rtn' => '08019923456789',
                'company_name' => 'Suministros del Valle, S. de R.L.',
                'contact_name' => 'Andrea Mejía',
                'email' => 'contacto@svalle.hn',
                'phone' => '2559-9900',
                'address' => 'Col. Las Acacias, calle 3',
                'city' => 'San Pedro Sula',
                'department' => 'Cortés',
                'credit_days' => 0,
                'is_active' => true,
            ],
            // ─── Inactivos ────────────────────────────────────────────────
            [
                'name' => 'Distribuidora Antigua',
                'rtn' => '08019945612378',
                'company_name' => 'Distribuidora Antigua, S. de R.L.',
                'contact_name' => 'Sin contacto',
                'email' => null,
                'phone' => '2540-0000',
                'address' => 'Dirección sin actualizar',
                'city' => 'San Pedro Sula',
                'department' => 'Cortés',
                'credit_days' => 0,
                'is_active' => false,
            ],
            [
                'name' => 'Proveedor Cesado',
                'rtn' => '08019978912345',
                'company_name' => 'Proveedor Cesado, S.A.',
                'contact_name' => null,
                'email' => null,
                'phone' => null,
                'address' => null,
                'city' => 'San Pedro Sula',
                'department' => 'Cortés',
                'credit_days' => 0,
                'is_active' => false,
            ],
        ];

        foreach ($proveedores as $data) {
            Supplier::firstOrCreate(
                ['rtn' => $data['rtn']],
                array_merge($data, [
                    'is_generic' => false,
                    'created_by' => $carlos->id,
                ])
            );
        }

        $this->command?->info(sprintf(
            'Proveedores demo creados: %d activos + %d inactivos (genérico RI ya existe via migración)',
            6,
            2,
        ));
    }
}
