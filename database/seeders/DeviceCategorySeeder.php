<?php

namespace Database\Seeders;

use App\Models\DeviceCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Categorías de equipo recibido en taller.
 *
 * Idempotente: usa updateOrCreate sobre `slug` para no duplicar entre runs.
 * Cuando se agregue una categoría, solo se añade al array — el seeder no
 * necesita cambios estructurales.
 */
class DeviceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Laptop',     'icon' => 'heroicon-o-computer-desktop',     'sort_order' => 10],
            ['name' => 'Desktop',    'icon' => 'heroicon-o-computer-desktop',     'sort_order' => 20],
            ['name' => 'Tablet',     'icon' => 'heroicon-o-device-tablet',        'sort_order' => 30],
            ['name' => 'Teléfono',   'icon' => 'heroicon-o-device-phone-mobile',  'sort_order' => 40],
            ['name' => 'Consola',    'icon' => 'heroicon-o-puzzle-piece',         'sort_order' => 50],
            ['name' => 'Control',    'icon' => 'heroicon-o-cube',                 'sort_order' => 60],
            ['name' => 'Monitor',    'icon' => 'heroicon-o-tv',                   'sort_order' => 70],
            ['name' => 'Impresora',  'icon' => 'heroicon-o-printer',              'sort_order' => 80],
            ['name' => 'Componente', 'icon' => 'heroicon-o-cpu-chip',             'sort_order' => 90],
            ['name' => 'Otro',       'icon' => 'heroicon-o-question-mark-circle', 'sort_order' => 999],
        ];

        foreach ($categories as $category) {
            DeviceCategory::updateOrCreate(
                ['slug' => Str::slug($category['name'])],
                [
                    'name' => $category['name'],
                    'icon' => $category['icon'],
                    'sort_order' => $category['sort_order'],
                    'is_active' => true,
                ]
            );
        }
    }
}
