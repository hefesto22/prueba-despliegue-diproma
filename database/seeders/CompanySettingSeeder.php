<?php

namespace Database\Seeders;

use App\Models\CompanySetting;
use App\Models\Establishment;
use Illuminate\Database\Seeder;

class CompanySettingSeeder extends Seeder
{
    public function run(): void
    {
        $company = CompanySetting::updateOrCreate(
            ['id' => 1],
            [
                'legal_name' => 'Diproma, S. de R.L.',
                'trade_name' => 'Diproma',
                'rtn' => '08019995123456',
                'business_type' => 'Venta de productos',
                'address' => 'Boulevard principal, San Pedro Sula',
                'city' => 'San Pedro Sula',
                'department' => 'Cortés',
                'municipality' => 'San Pedro Sula',
                'phone' => '2550-0000',
                'email' => 'contacto@diproma.hn',
                'tax_regime' => 'normal',
            ]
        );

        // Establecimiento matriz (único hoy, expandible a N mañana)
        Establishment::updateOrCreate(
            [
                'company_setting_id' => $company->id,
                'code' => '001',
                'emission_point' => '001',
            ],
            [
                'name' => 'Matriz',
                'type' => 'fijo',
                'address' => $company->address,
                'city' => $company->city,
                'department' => $company->department,
                'municipality' => $company->municipality,
                'phone' => $company->phone,
                'is_main' => true,
                'is_active' => true,
            ]
        );
    }
}
