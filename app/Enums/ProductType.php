<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Tipos de producto con campos de especificación dinámicos.
 *
 * Cada spec field define:
 * - key: clave de almacenamiento en JSON (coincide con field_key en spec_options)
 * - label: etiqueta en español
 * - type: 'select' (opciones desde BD) o 'text' (libre)
 * - placeholder: texto de ayuda (solo si type=text)
 * - key_spec: true si aparece en el nombre autogenerado
 *
 * Las OPCIONES de cada campo 'select' ya no viven aquí.
 * Se almacenan en la tabla `spec_options` y se administran desde el panel.
 */
enum ProductType: string implements HasLabel
{
    case Laptop = 'laptop';
    case Desktop = 'desktop';
    case Tablet = 'tablet';
    case Console = 'console';
    case Monitor = 'monitor';
    case Printer = 'printer';
    case Component = 'component';
    case Accessory = 'accessory';

    public function getLabel(): string
    {
        return match ($this) {
            self::Laptop => 'Laptop',
            self::Desktop => 'Desktop / PC',
            self::Tablet => 'Tablet',
            self::Console => 'Consola',
            self::Monitor => 'Monitor',
            self::Printer => 'Impresora',
            self::Component => 'Componente',
            self::Accessory => 'Accesorio',
        };
    }

    public function skuPrefix(): string
    {
        return match ($this) {
            self::Laptop => 'LAP',
            self::Desktop => 'DES',
            self::Tablet => 'TAB',
            self::Console => 'CON',
            self::Monitor => 'MON',
            self::Printer => 'IMP',
            self::Component => 'COM',
            self::Accessory => 'ACC',
        };
    }

    /**
     * Campos de especificación para este tipo de producto.
     *
     * @return array<int, array{key: string, label: string, type: string, placeholder?: string, key_spec: bool}>
     */
    public function specFields(): array
    {
        return match ($this) {
            self::Laptop => [
                ['key' => 'processor',  'label' => 'Procesador',         'type' => 'select', 'key_spec' => true],
                ['key' => 'ram',        'label' => 'RAM',                'type' => 'select', 'key_spec' => true],
                ['key' => 'storage',    'label' => 'Almacenamiento',     'type' => 'select', 'key_spec' => true],
                ['key' => 'screen',     'label' => 'Pantalla',           'type' => 'select', 'key_spec' => false],
                ['key' => 'gpu',        'label' => 'Gráficos',           'type' => 'select', 'key_spec' => false],
                ['key' => 'os',         'label' => 'Sistema operativo',  'type' => 'select', 'key_spec' => false],
            ],
            self::Desktop => [
                ['key' => 'processor',  'label' => 'Procesador',         'type' => 'select', 'key_spec' => true],
                ['key' => 'ram',        'label' => 'RAM',                'type' => 'select', 'key_spec' => true],
                ['key' => 'storage',    'label' => 'Almacenamiento',     'type' => 'select', 'key_spec' => true],
                ['key' => 'gpu',        'label' => 'Gráficos',           'type' => 'select', 'key_spec' => false],
                ['key' => 'case_type',  'label' => 'Gabinete',           'type' => 'select', 'key_spec' => false],
                ['key' => 'os',         'label' => 'Sistema operativo',  'type' => 'select', 'key_spec' => false],
            ],
            self::Tablet => [
                ['key' => 'screen',       'label' => 'Pantalla',           'type' => 'select', 'key_spec' => true],
                ['key' => 'storage',      'label' => 'Almacenamiento',     'type' => 'select', 'key_spec' => true],
                ['key' => 'connectivity', 'label' => 'Conectividad',       'type' => 'select', 'key_spec' => false],
                ['key' => 'os',           'label' => 'Sistema operativo',  'type' => 'select', 'key_spec' => false],
            ],
            self::Console => [
                ['key' => 'edition',       'label' => 'Edición',          'type' => 'select', 'key_spec' => true],
                ['key' => 'storage',       'label' => 'Almacenamiento',   'type' => 'select', 'key_spec' => true],
                ['key' => 'console_color', 'label' => 'Color',            'type' => 'text',   'key_spec' => false, 'placeholder' => 'BLANCO, NEGRO'],
                ['key' => 'bundled_items', 'label' => 'Incluye',          'type' => 'text',   'key_spec' => false, 'placeholder' => '1 CONTROL, CABLES, JUEGO'],
            ],
            self::Monitor => [
                ['key' => 'screen',     'label' => 'Tamaño',       'type' => 'select', 'key_spec' => true],
                ['key' => 'resolution', 'label' => 'Resolución',   'type' => 'select', 'key_spec' => true],
                ['key' => 'panel',      'label' => 'Panel',        'type' => 'select', 'key_spec' => false],
                ['key' => 'refresh',    'label' => 'Frecuencia',   'type' => 'select', 'key_spec' => false],
                ['key' => 'ports',      'label' => 'Puertos',      'type' => 'text',   'key_spec' => false, 'placeholder' => 'HDMI, DISPLAYPORT, USB-C, VGA'],
            ],
            self::Printer => [
                ['key' => 'printer_type',      'label' => 'Tipo',          'type' => 'select', 'key_spec' => true],
                ['key' => 'printer_functions', 'label' => 'Funciones',     'type' => 'select', 'key_spec' => false],
                ['key' => 'printer_conn',      'label' => 'Conectividad',  'type' => 'select', 'key_spec' => false],
            ],
            self::Component => [
                ['key' => 'component_type', 'label' => 'Tipo',       'type' => 'select', 'key_spec' => true],
                ['key' => 'capacity',       'label' => 'Capacidad',  'type' => 'text',   'key_spec' => true,  'placeholder' => '8 GB, 16 GB (2X8 GB), 1 TB, 750W'],
                ['key' => 'comp_speed',     'label' => 'Velocidad',  'type' => 'text',   'key_spec' => false, 'placeholder' => '3200 MHZ, 7000 MB/S'],
                ['key' => 'comp_interface', 'label' => 'Interfaz',   'type' => 'select', 'key_spec' => false],
            ],
            self::Accessory => [
                ['key' => 'accessory_type', 'label' => 'Tipo',          'type' => 'select', 'key_spec' => true],
                ['key' => 'connectivity',   'label' => 'Conectividad',  'type' => 'select', 'key_spec' => false],
                ['key' => 'acc_color',      'label' => 'Color',         'type' => 'text',   'key_spec' => false, 'placeholder' => 'NEGRO, BLANCO, RGB'],
            ],
        };
    }

    public function keySpecKeys(): array
    {
        return collect($this->specFields())
            ->filter(fn ($field) => $field['key_spec'])
            ->pluck('key')
            ->toArray();
    }

    /**
     * Generar nombre en MAYÚSCULAS.
     * Formato: "LAPTOP HP PROBOOK 450 - INTEL CORE I7 12VA GEN / 16 GB / 512 GB SSD"
     */
    public function generateName(string $brand, string $model, array $specs): string
    {
        $parts = [mb_strtoupper($this->getLabel())];

        if (filled($brand)) {
            $parts[] = mb_strtoupper($brand);
        }

        if (filled($model)) {
            $parts[] = mb_strtoupper($model);
        }

        $base = implode(' ', $parts);

        $keySpecs = collect($this->keySpecKeys())
            ->map(fn ($key) => $specs[$key] ?? null)
            ->filter(fn ($val) => filled($val))
            ->map(fn ($val) => mb_strtoupper($val))
            ->values()
            ->implode(' / ');

        if (filled($keySpecs)) {
            return "{$base} - {$keySpecs}";
        }

        return $base;
    }
}
