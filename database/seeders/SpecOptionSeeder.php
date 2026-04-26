<?php

namespace Database\Seeders;

use App\Models\SpecOption;
use Illuminate\Database\Seeder;

class SpecOptionSeeder extends Seeder
{
    public function run(): void
    {
        $data = $this->allOptions();

        foreach ($data as $fieldKey => $values) {
            foreach (array_values($values) as $sort => $value) {
                SpecOption::firstOrCreate(
                    ['field_key' => $fieldKey, 'value' => $value],
                    ['sort_order' => $sort, 'is_active' => true]
                );
            }
        }
    }

    private function allOptions(): array
    {
        return [
            'processor' => $this->processors(),
            'ram' => $this->ram(),
            'storage' => $this->storage(),
            'gpu' => $this->gpus(),
            'screen' => $this->screens(),
            'os' => $this->operatingSystems(),
            'case_type' => $this->caseTypes(),
            'connectivity' => $this->connectivity(),
            'edition' => $this->consoleEditions(),
            'resolution' => $this->monitorResolutions(),
            'panel' => $this->monitorPanels(),
            'refresh' => $this->refreshRates(),
            'printer_type' => $this->printerTypes(),
            'printer_functions' => $this->printerFunctions(),
            'printer_conn' => $this->printerConnectivity(),
            'component_type' => $this->componentTypes(),
            'comp_interface' => $this->componentInterfaces(),
            'accessory_type' => $this->accessoryTypes(),
        ];
    }

    // ─── Procesadores ───────────────────────────────────────

    private function processors(): array
    {
        $processors = [];

        // Intel Core i3 con generaciones
        $processors[] = 'INTEL CORE I3';
        foreach ($this->intelGens(4) as $gen) {
            $processors[] = "INTEL CORE I3 {$gen}";
        }

        // Intel Core i5
        $processors[] = 'INTEL CORE I5';
        foreach ($this->intelGens(4) as $gen) {
            $processors[] = "INTEL CORE I5 {$gen}";
        }

        // Intel Core i7
        $processors[] = 'INTEL CORE I7';
        foreach ($this->intelGens(4) as $gen) {
            $processors[] = "INTEL CORE I7 {$gen}";
        }

        // Intel Core i9 (desde 9na gen)
        $processors[] = 'INTEL CORE I9';
        foreach ($this->intelGens(9) as $gen) {
            $processors[] = "INTEL CORE I9 {$gen}";
        }

        // Intel Core Ultra
        array_push($processors,
            'INTEL CORE ULTRA 5',
            'INTEL CORE ULTRA 7',
            'INTEL CORE ULTRA 9',
        );

        // Intel budget / N-series
        array_push($processors,
            'INTEL CELERON',
            'INTEL PENTIUM',
            'INTEL N95',
            'INTEL N100',
            'INTEL N200',
        );

        // AMD Ryzen con series
        foreach (['3', '5', '7', '9'] as $tier) {
            $processors[] = "AMD RYZEN {$tier}";
            foreach (['3000', '4000', '5000', '6000', '7000', '8000', '9000'] as $series) {
                $processors[] = "AMD RYZEN {$tier} {$series}";
            }
        }
        $processors[] = 'AMD ATHLON';

        // Apple Silicon
        foreach (['M1', 'M2', 'M3', 'M4'] as $chip) {
            $processors[] = "APPLE {$chip}";
            $processors[] = "APPLE {$chip} PRO";
            $processors[] = "APPLE {$chip} MAX";
        }
        $processors[] = 'APPLE M2 ULTRA';

        // Qualcomm
        array_push($processors,
            'SNAPDRAGON X ELITE',
            'SNAPDRAGON X PLUS',
        );

        return $processors;
    }

    private function intelGens(int $from = 4): array
    {
        $labels = [
            4 => '4TA GEN', 5 => '5TA GEN', 6 => '6TA GEN',
            7 => '7MA GEN', 8 => '8VA GEN', 9 => '9NA GEN',
            10 => '10MA GEN', 11 => '11VA GEN', 12 => '12VA GEN',
            13 => '13VA GEN', 14 => '14VA GEN',
        ];

        return array_values(array_filter(
            $labels,
            fn ($k) => $k >= $from,
            ARRAY_FILTER_USE_KEY
        ));
    }

    // ─── RAM ────────────────────────────────────────────────

    private function ram(): array
    {
        return [
            '2 GB', '4 GB', '6 GB', '8 GB', '12 GB',
            '16 GB', '24 GB', '32 GB', '36 GB', '48 GB',
            '64 GB', '128 GB',
        ];
    }

    // ─── Almacenamiento ─────────────────────────────────────

    private function storage(): array
    {
        return [
            // SSD SATA
            '120 GB SSD', '128 GB SSD', '240 GB SSD', '256 GB SSD',
            '480 GB SSD', '500 GB SSD', '512 GB SSD', '960 GB SSD',
            '1 TB SSD', '2 TB SSD', '4 TB SSD',
            // SSD NVME (M.2)
            '128 GB SSD NVME', '256 GB SSD NVME', '512 GB SSD NVME',
            '1 TB SSD NVME', '2 TB SSD NVME', '4 TB SSD NVME',
            // HDD
            '160 GB HDD', '250 GB HDD', '320 GB HDD', '500 GB HDD',
            '750 GB HDD', '1 TB HDD', '2 TB HDD', '4 TB HDD', '8 TB HDD',
            // Combos HDD + SSD
            '500 GB HDD + 128 GB SSD', '500 GB HDD + 256 GB SSD',
            '1 TB HDD + 128 GB SSD', '1 TB HDD + 256 GB SSD',
            '1 TB HDD + 512 GB SSD',
            '2 TB HDD + 256 GB SSD', '2 TB HDD + 512 GB SSD',
            '2 TB HDD + 1 TB SSD',
            // eMMC
            '32 GB EMMC', '64 GB EMMC', '128 GB EMMC',
        ];
    }

    // ─── Gráficos / GPU ───────────────────────────────────

    private function gpus(): array
    {
        return [
            // Integradas
            'INTEGRADA',
            'INTEL UHD GRAPHICS',
            'INTEL IRIS XE',
            'INTEL IRIS PLUS',
            'INTEL UHD 630',
            'INTEL UHD 730',
            'INTEL UHD 770',
            'INTEL ARC A370M',
            'INTEL ARC A550M',
            'INTEL ARC A770M',
            'AMD RADEON INTEGRADA',
            'AMD RADEON VEGA 8',
            'AMD RADEON 680M',
            'AMD RADEON 780M',
            'APPLE GPU (M1)',
            'APPLE GPU (M2)',
            'APPLE GPU (M3)',
            'APPLE GPU (M4)',
            // NVIDIA Laptop (Mobile)
            'NVIDIA GTX 1650',
            'NVIDIA GTX 1660 TI',
            'NVIDIA RTX 2060',
            'NVIDIA RTX 2070',
            'NVIDIA RTX 3050',
            'NVIDIA RTX 3050 TI',
            'NVIDIA RTX 3060',
            'NVIDIA RTX 3070',
            'NVIDIA RTX 3070 TI',
            'NVIDIA RTX 3080',
            'NVIDIA RTX 4050',
            'NVIDIA RTX 4060',
            'NVIDIA RTX 4070',
            'NVIDIA RTX 4080',
            'NVIDIA RTX 4090',
            'NVIDIA RTX 5070',
            'NVIDIA RTX 5080',
            'NVIDIA RTX 5090',
            // NVIDIA Desktop
            'NVIDIA GT 1030',
            'NVIDIA GTX 1650 SUPER',
            'NVIDIA GTX 1660 SUPER',
            'NVIDIA RTX 3060 12GB',
            'NVIDIA RTX 3070 8GB',
            'NVIDIA RTX 4060 8GB',
            'NVIDIA RTX 4060 TI 8GB',
            'NVIDIA RTX 4060 TI 16GB',
            'NVIDIA RTX 4070 12GB',
            'NVIDIA RTX 4070 SUPER',
            'NVIDIA RTX 4070 TI SUPER',
            'NVIDIA RTX 4080 SUPER',
            'NVIDIA RTX 4090 24GB',
            // AMD Discrete
            'AMD RADEON RX 6500 XT',
            'AMD RADEON RX 6600',
            'AMD RADEON RX 6600 XT',
            'AMD RADEON RX 6700 XT',
            'AMD RADEON RX 6800',
            'AMD RADEON RX 6800 XT',
            'AMD RADEON RX 7600',
            'AMD RADEON RX 7700 XT',
            'AMD RADEON RX 7800 XT',
            'AMD RADEON RX 7900 XT',
            'AMD RADEON RX 7900 XTX',
            // Workstation
            'NVIDIA QUADRO T500',
            'NVIDIA QUADRO T1000',
            'NVIDIA RTX A2000',
            'NVIDIA RTX A4000',
        ];
    }

    // ─── Pantallas ──────────────────────────────────────────

    private function screens(): array
    {
        return [
            // Laptop
            '11.6" HD', '13.3" HD', '13.3" FHD', '13.3" QHD',
            '14" HD', '14" FHD', '14" FHD TACTIL', '14" QHD', '14" OLED',
            '15.6" HD', '15.6" FHD', '15.6" FHD TACTIL', '15.6" QHD', '15.6" OLED',
            '16" FHD', '16" QHD', '16" OLED',
            '17.3" FHD', '17.3" QHD',
            // Tablet
            '7" HD', '8" HD', '8.3" RETINA',
            '10.1" FHD', '10.2" RETINA', '10.5" FHD', '10.9" RETINA',
            '11" RETINA', '11" AMOLED',
            '12.4" AMOLED', '12.9" RETINA', '13" RETINA',
            // Monitor
            '18.5"', '19"', '19.5"', '21.5"', '23.8"', '24"',
            '27"', '28"', '32"',
            '34" ULTRAWIDE', '38" ULTRAWIDE', '49" ULTRAWIDE',
        ];
    }

    // ─── Sistemas operativos ────────────────────────────────

    private function operatingSystems(): array
    {
        return [
            'WINDOWS 11 HOME', 'WINDOWS 11 PRO',
            'WINDOWS 10 HOME', 'WINDOWS 10 PRO',
            'WINDOWS SERVER',
            'MACOS', 'CHROME OS', 'LINUX',
            'DOS / SIN OS',
            // Tablet / Móvil
            'IPADOS', 'ANDROID', 'FIRE OS', 'HARMONY OS',
        ];
    }

    // ─── Gabinetes (Desktop) ────────────────────────────────

    private function caseTypes(): array
    {
        return [
            'TORRE', 'MINI TORRE', 'SFF', 'MICRO',
            'ALL IN ONE', 'MINI PC',
        ];
    }

    // ─── Conectividad ───────────────────────────────────────

    private function connectivity(): array
    {
        return [
            'USB', 'USB-C', 'BLUETOOTH',
            'INALAMBRICO 2.4 GHZ', 'USB + BLUETOOTH',
            'JACK 3.5 MM', 'WIFI', 'WIFI + CELLULAR',
            'USB + WIFI', 'USB + WIFI + ETHERNET',
            'HDMI', 'DISPLAYPORT', 'THUNDERBOLT', 'ETHERNET',
        ];
    }

    // ─── Ediciones de consola ───────────────────────────────

    private function consoleEditions(): array
    {
        return [
            'STANDARD', 'DIGITAL', 'PRO', 'SLIM',
            'OLED', 'LITE', 'SERIES X', 'SERIES S', 'ALL DIGITAL',
        ];
    }

    // ─── Monitor: resolución ────────────────────────────────

    private function monitorResolutions(): array
    {
        return [
            'HD 1366X768', 'FHD 1920X1080', 'QHD 2560X1440',
            '4K 3840X2160', 'UWFHD 2560X1080',
            'UWQHD 3440X1440', '5K 5120X2880',
        ];
    }

    // ─── Monitor: paneles ───────────────────────────────────

    private function monitorPanels(): array
    {
        return ['IPS', 'VA', 'TN', 'OLED', 'MINI LED'];
    }

    // ─── Monitor: frecuencia ────────────────────────────────

    private function refreshRates(): array
    {
        return [
            '60 HZ', '75 HZ', '100 HZ', '120 HZ',
            '144 HZ', '165 HZ', '180 HZ', '240 HZ', '360 HZ',
        ];
    }

    // ─── Impresoras: tipo ───────────────────────────────────

    private function printerTypes(): array
    {
        return [
            'INYECCION DE TINTA', 'LASER', 'LASER COLOR',
            'TERMICA', 'TERMICA 80MM', 'TERMICA 58MM',
            'MATRIZ DE PUNTO', 'PLOTTER', 'SUBLIMACION',
        ];
    }

    // ─── Impresoras: funciones ──────────────────────────────

    private function printerFunctions(): array
    {
        return [
            'IMPRESION', 'IMPRESION + COPIA',
            'IMPRESION + COPIA + ESCANER',
            'MULTIFUNCION (TODO)', 'IMPRESION + FAX',
        ];
    }

    // ─── Impresoras: conectividad ─────────────────────────────

    private function printerConnectivity(): array
    {
        return [
            'SOLO USB',
            'SOLO WIFI',
            'USB + WIFI',
            'USB + ETHERNET',
            'USB + WIFI + ETHERNET',
            'BLUETOOTH',
            'USB + BLUETOOTH',
        ];
    }

    // ─── Componentes: tipo ──────────────────────────────────

    private function componentTypes(): array
    {
        return [
            'MEMORIA RAM', 'SSD SATA', 'SSD NVME M.2', 'HDD',
            'GPU / TARJETA DE VIDEO', 'MOTHERBOARD', 'PROCESADOR',
            'FUENTE DE PODER', 'GABINETE',
            'COOLER / VENTILADOR', 'DISIPADOR LIQUIDO',
            'TARJETA DE RED', 'TARJETA DE SONIDO', 'UNIDAD OPTICA',
        ];
    }

    // ─── Componentes: interfaz ──────────────────────────────

    private function componentInterfaces(): array
    {
        return [
            'DDR3', 'DDR4', 'DDR5',
            'SATA III', 'NVME M.2',
            'PCIE 3.0', 'PCIE 4.0', 'PCIE 5.0',
            'USB 3.0', 'USB 3.2',
            'LGA 1200', 'LGA 1700', 'AM4', 'AM5',
        ];
    }

    // ─── Accesorios: tipo ───────────────────────────────────

    private function accessoryTypes(): array
    {
        return [
            'TECLADO', 'MOUSE', 'COMBO TECLADO + MOUSE',
            'AUDIFONOS', 'BOCINAS', 'WEBCAM', 'MICROFONO',
            'MOUSEPAD', 'HUB USB', 'DOCKING STATION',
            'CABLE HDMI', 'CABLE USB-C', 'CABLE DISPLAYPORT',
            'CABLE VGA', 'CABLE RED / ETHERNET',
            'ADAPTADOR', 'CARGADOR', 'BATERIA',
            'FUNDA', 'MOCHILA', 'BASE / SOPORTE',
            'CONTROL / GAMEPAD',
            'MEMORIA USB', 'MEMORIA SD', 'DISCO EXTERNO',
            'UPS / REGULADOR', 'ROUTER', 'SWITCH DE RED', 'ACCESS POINT',
        ];
    }
}
