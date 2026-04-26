<?php

namespace Database\Seeders;

use App\Enums\ProductCondition;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Categorías ─────────────────────────────────────────
        $laptops = Category::firstOrCreate(
            ['slug' => 'laptops'],
            ['name' => 'Laptops', 'is_active' => true, 'sort_order' => 1]
        );

        $desktops = Category::firstOrCreate(
            ['slug' => 'desktops'],
            ['name' => 'Desktops / PCs', 'is_active' => true, 'sort_order' => 2]
        );

        $accesorios = Category::firstOrCreate(
            ['slug' => 'accesorios'],
            ['name' => 'Accesorios', 'is_active' => true, 'sort_order' => 3]
        );

        // ─── Laptops Nuevas (5) ─────────────────────────────────
        $this->createProduct($laptops, ProductType::Laptop, ProductCondition::New, [
            'brand' => 'HP',
            'model' => 'PROBOOK 450 G10',
            'cost_price' => 14500,
            'sale_price' => 18975,
            'specs' => ['processor' => 'INTEL CORE I5 13VA GEN', 'ram' => '16 GB', 'storage' => '512 GB SSD NVME', 'screen' => '15.6" FHD', 'gpu' => 'INTEL IRIS XE', 'os' => 'WINDOWS 11 PRO'],
        ]);

        $this->createProduct($laptops, ProductType::Laptop, ProductCondition::New, [
            'brand' => 'LENOVO',
            'model' => 'THINKPAD E16 GEN 1',
            'cost_price' => 12800,
            'sale_price' => 16750,
            'specs' => ['processor' => 'INTEL CORE I5 13VA GEN', 'ram' => '8 GB', 'storage' => '256 GB SSD NVME', 'screen' => '16" FHD', 'gpu' => 'INTEL IRIS XE', 'os' => 'WINDOWS 11 PRO'],
        ]);

        $this->createProduct($laptops, ProductType::Laptop, ProductCondition::New, [
            'brand' => 'DELL',
            'model' => 'LATITUDE 5540',
            'cost_price' => 18200,
            'sale_price' => 23850,
            'specs' => ['processor' => 'INTEL CORE I7 13VA GEN', 'ram' => '16 GB', 'storage' => '512 GB SSD NVME', 'screen' => '15.6" FHD', 'gpu' => 'INTEL IRIS XE', 'os' => 'WINDOWS 11 PRO'],
        ]);

        $this->createProduct($laptops, ProductType::Laptop, ProductCondition::New, [
            'brand' => 'ASUS',
            'model' => 'VIVOBOOK 15 X1504',
            'cost_price' => 8500,
            'sale_price' => 11150,
            'specs' => ['processor' => 'INTEL CORE I3 13VA GEN', 'ram' => '8 GB', 'storage' => '256 GB SSD NVME', 'screen' => '15.6" FHD', 'gpu' => 'INTEL UHD GRAPHICS', 'os' => 'WINDOWS 11 HOME'],
        ]);

        $this->createProduct($laptops, ProductType::Laptop, ProductCondition::New, [
            'brand' => 'ACER',
            'model' => 'ASPIRE 5 A515-58',
            'cost_price' => 10200,
            'sale_price' => 13350,
            'specs' => ['processor' => 'INTEL CORE I5 13VA GEN', 'ram' => '8 GB', 'storage' => '512 GB SSD NVME', 'screen' => '15.6" FHD', 'gpu' => 'INTEL IRIS XE', 'os' => 'WINDOWS 11 HOME'],
        ]);

        // ─── Laptops Usadas (5) ─────────────────────────────────
        $this->createProduct($laptops, ProductType::Laptop, ProductCondition::Used, [
            'brand' => 'HP',
            'model' => 'ELITEBOOK 840 G5',
            'cost_price' => 4500,
            'sale_price' => 7500,
            'specs' => ['processor' => 'INTEL CORE I5 8VA GEN', 'ram' => '8 GB', 'storage' => '256 GB SSD', 'screen' => '14" FHD', 'gpu' => 'INTEL UHD GRAPHICS', 'os' => 'WINDOWS 10 PRO'],
        ]);

        $this->createProduct($laptops, ProductType::Laptop, ProductCondition::Used, [
            'brand' => 'DELL',
            'model' => 'LATITUDE 5410',
            'cost_price' => 5200,
            'sale_price' => 8500,
            'specs' => ['processor' => 'INTEL CORE I5 10MA GEN', 'ram' => '16 GB', 'storage' => '256 GB SSD NVME', 'screen' => '14" FHD', 'gpu' => 'INTEL UHD GRAPHICS', 'os' => 'WINDOWS 10 PRO'],
        ]);

        $this->createProduct($laptops, ProductType::Laptop, ProductCondition::Used, [
            'brand' => 'LENOVO',
            'model' => 'THINKPAD T480',
            'cost_price' => 3800,
            'sale_price' => 6200,
            'specs' => ['processor' => 'INTEL CORE I5 8VA GEN', 'ram' => '8 GB', 'storage' => '256 GB SSD', 'screen' => '14" FHD', 'gpu' => 'INTEL UHD GRAPHICS', 'os' => 'WINDOWS 10 PRO'],
        ]);

        $this->createProduct($laptops, ProductType::Laptop, ProductCondition::Used, [
            'brand' => 'HP',
            'model' => 'PROBOOK 440 G7',
            'cost_price' => 4800,
            'sale_price' => 7800,
            'specs' => ['processor' => 'INTEL CORE I5 10MA GEN', 'ram' => '8 GB', 'storage' => '256 GB SSD NVME', 'screen' => '14" FHD', 'gpu' => 'INTEL UHD GRAPHICS', 'os' => 'WINDOWS 10 PRO'],
        ]);

        $this->createProduct($laptops, ProductType::Laptop, ProductCondition::Used, [
            'brand' => 'DELL',
            'model' => 'INSPIRON 15 3501',
            'cost_price' => 3200,
            'sale_price' => 5500,
            'specs' => ['processor' => 'INTEL CORE I3 11VA GEN', 'ram' => '4 GB', 'storage' => '128 GB SSD', 'screen' => '15.6" HD', 'gpu' => 'INTEL UHD GRAPHICS', 'os' => 'WINDOWS 10 HOME'],
        ]);

        // ─── Desktops Nuevos (5) ────────────────────────────────
        $this->createProduct($desktops, ProductType::Desktop, ProductCondition::New, [
            'brand' => 'HP',
            'model' => 'PRODESK 400 G9 SFF',
            'cost_price' => 13500,
            'sale_price' => 17650,
            'specs' => ['processor' => 'INTEL CORE I5 12VA GEN', 'ram' => '16 GB', 'storage' => '512 GB SSD NVME', 'gpu' => 'INTEL UHD 730', 'case_type' => 'SFF', 'os' => 'WINDOWS 11 PRO'],
        ]);

        $this->createProduct($desktops, ProductType::Desktop, ProductCondition::New, [
            'brand' => 'DELL',
            'model' => 'OPTIPLEX 7010 TOWER',
            'cost_price' => 15800,
            'sale_price' => 20700,
            'specs' => ['processor' => 'INTEL CORE I7 13VA GEN', 'ram' => '16 GB', 'storage' => '512 GB SSD NVME', 'gpu' => 'INTEL UHD 770', 'case_type' => 'TORRE', 'os' => 'WINDOWS 11 PRO'],
        ]);

        $this->createProduct($desktops, ProductType::Desktop, ProductCondition::New, [
            'brand' => 'LENOVO',
            'model' => 'THINKCENTRE M70Q GEN 4',
            'cost_price' => 11200,
            'sale_price' => 14650,
            'specs' => ['processor' => 'INTEL CORE I5 13VA GEN', 'ram' => '8 GB', 'storage' => '256 GB SSD NVME', 'gpu' => 'INTEL UHD 730', 'case_type' => 'MINI PC', 'os' => 'WINDOWS 11 PRO'],
        ]);

        $this->createProduct($desktops, ProductType::Desktop, ProductCondition::New, [
            'brand' => 'HP',
            'model' => 'ALL IN ONE 24-CR0001',
            'cost_price' => 9800,
            'sale_price' => 12850,
            'specs' => ['processor' => 'INTEL CORE I3 13VA GEN', 'ram' => '8 GB', 'storage' => '256 GB SSD NVME', 'gpu' => 'INTEL UHD GRAPHICS', 'case_type' => 'ALL IN ONE', 'os' => 'WINDOWS 11 HOME'],
        ]);

        $this->createProduct($desktops, ProductType::Desktop, ProductCondition::New, [
            'brand' => 'DELL',
            'model' => 'OPTIPLEX 3000 MICRO',
            'cost_price' => 10500,
            'sale_price' => 13750,
            'specs' => ['processor' => 'INTEL CORE I5 12VA GEN', 'ram' => '8 GB', 'storage' => '256 GB SSD NVME', 'gpu' => 'INTEL UHD 730', 'case_type' => 'MICRO', 'os' => 'WINDOWS 11 PRO'],
        ]);

        // ─── Desktops Usados (5) ────────────────────────────────
        $this->createProduct($desktops, ProductType::Desktop, ProductCondition::Used, [
            'brand' => 'HP',
            'model' => 'PRODESK 600 G4 SFF',
            'cost_price' => 3500,
            'sale_price' => 5800,
            'specs' => ['processor' => 'INTEL CORE I5 8VA GEN', 'ram' => '8 GB', 'storage' => '256 GB SSD', 'gpu' => 'INTEL UHD 630', 'case_type' => 'SFF', 'os' => 'WINDOWS 10 PRO'],
        ]);

        $this->createProduct($desktops, ProductType::Desktop, ProductCondition::Used, [
            'brand' => 'DELL',
            'model' => 'OPTIPLEX 7060 SFF',
            'cost_price' => 3800,
            'sale_price' => 6200,
            'specs' => ['processor' => 'INTEL CORE I5 8VA GEN', 'ram' => '16 GB', 'storage' => '512 GB SSD', 'gpu' => 'INTEL UHD 630', 'case_type' => 'SFF', 'os' => 'WINDOWS 10 PRO'],
        ]);

        $this->createProduct($desktops, ProductType::Desktop, ProductCondition::Used, [
            'brand' => 'LENOVO',
            'model' => 'THINKCENTRE M720S',
            'cost_price' => 3200,
            'sale_price' => 5200,
            'specs' => ['processor' => 'INTEL CORE I5 8VA GEN', 'ram' => '8 GB', 'storage' => '256 GB SSD', 'gpu' => 'INTEL UHD 630', 'case_type' => 'SFF', 'os' => 'WINDOWS 10 PRO'],
        ]);

        $this->createProduct($desktops, ProductType::Desktop, ProductCondition::Used, [
            'brand' => 'HP',
            'model' => 'ELITEDESK 800 G5 MINI',
            'cost_price' => 4200,
            'sale_price' => 6800,
            'specs' => ['processor' => 'INTEL CORE I5 9NA GEN', 'ram' => '8 GB', 'storage' => '256 GB SSD NVME', 'gpu' => 'INTEL UHD 630', 'case_type' => 'MINI PC', 'os' => 'WINDOWS 10 PRO'],
        ]);

        $this->createProduct($desktops, ProductType::Desktop, ProductCondition::Used, [
            'brand' => 'DELL',
            'model' => 'OPTIPLEX 5070 TORRE',
            'cost_price' => 4500,
            'sale_price' => 7200,
            'specs' => ['processor' => 'INTEL CORE I7 9NA GEN', 'ram' => '16 GB', 'storage' => '512 GB SSD', 'gpu' => 'INTEL UHD 630', 'case_type' => 'TORRE', 'os' => 'WINDOWS 10 PRO'],
        ]);

        // ─── Accesorios Nuevos (5) ──────────────────────────────
        $this->createProduct($accesorios, ProductType::Accessory, ProductCondition::New, [
            'brand' => 'LOGITECH',
            'model' => 'MK270',
            'cost_price' => 450,
            'sale_price' => 695,
            'specs' => ['accessory_type' => 'COMBO TECLADO + MOUSE', 'connectivity' => 'INALAMBRICO 2.4 GHZ'],
        ]);

        $this->createProduct($accesorios, ProductType::Accessory, ProductCondition::New, [
            'brand' => 'LOGITECH',
            'model' => 'M190',
            'cost_price' => 180,
            'sale_price' => 295,
            'specs' => ['accessory_type' => 'MOUSE', 'connectivity' => 'INALAMBRICO 2.4 GHZ'],
        ]);

        $this->createProduct($accesorios, ProductType::Accessory, ProductCondition::New, [
            'brand' => 'HP',
            'model' => '235',
            'cost_price' => 320,
            'sale_price' => 495,
            'specs' => ['accessory_type' => 'COMBO TECLADO + MOUSE', 'connectivity' => 'INALAMBRICO 2.4 GHZ'],
        ]);

        $this->createProduct($accesorios, ProductType::Accessory, ProductCondition::New, [
            'brand' => 'KINGSTON',
            'model' => 'DATATRAVELER EXODIA 64GB',
            'cost_price' => 120,
            'sale_price' => 195,
            'specs' => ['accessory_type' => 'MEMORIA USB', 'connectivity' => 'USB'],
        ]);

        $this->createProduct($accesorios, ProductType::Accessory, ProductCondition::New, [
            'brand' => 'TARGUS',
            'model' => 'CLASSIC 15.6"',
            'cost_price' => 350,
            'sale_price' => 550,
            'specs' => ['accessory_type' => 'MOCHILA', 'connectivity' => null],
        ]);

        // ─── Accesorios Usados (5) ──────────────────────────────
        $this->createProduct($accesorios, ProductType::Accessory, ProductCondition::Used, [
            'brand' => 'LOGITECH',
            'model' => 'C920 HD PRO',
            'cost_price' => 400,
            'sale_price' => 750,
            'specs' => ['accessory_type' => 'WEBCAM', 'connectivity' => 'USB'],
        ]);

        $this->createProduct($accesorios, ProductType::Accessory, ProductCondition::Used, [
            'brand' => 'APC',
            'model' => 'BACK-UPS 600VA',
            'cost_price' => 600,
            'sale_price' => 1100,
            'specs' => ['accessory_type' => 'UPS / REGULADOR'],
        ]);

        $this->createProduct($accesorios, ProductType::Accessory, ProductCondition::Used, [
            'brand' => 'LOGITECH',
            'model' => 'H390',
            'cost_price' => 250,
            'sale_price' => 450,
            'specs' => ['accessory_type' => 'AUDIFONOS', 'connectivity' => 'USB'],
        ]);

        $this->createProduct($accesorios, ProductType::Accessory, ProductCondition::Used, [
            'brand' => 'TP-LINK',
            'model' => 'ARCHER C6',
            'cost_price' => 350,
            'sale_price' => 650,
            'specs' => ['accessory_type' => 'ROUTER'],
        ]);

        $this->createProduct($accesorios, ProductType::Accessory, ProductCondition::Used, [
            'brand' => 'SAMSUNG',
            'model' => 'T7 500GB',
            'cost_price' => 700,
            'sale_price' => 1200,
            'specs' => ['accessory_type' => 'DISCO EXTERNO', 'connectivity' => 'USB-C'],
        ]);

        // ─── Tablets Nuevas (5) ─────────────────────────────────
        $tablets = Category::firstOrCreate(
            ['slug' => 'tablets'],
            ['name' => 'Tablets', 'is_active' => true, 'sort_order' => 4]
        );

        $this->createProduct($tablets, ProductType::Tablet, ProductCondition::New, [
            'brand' => 'APPLE',
            'model' => 'IPAD 10MA GEN',
            'cost_price' => 8500,
            'sale_price' => 11500,
            'specs' => ['screen' => '10.9" RETINA', 'storage' => '64 GB EMMC', 'connectivity' => 'WIFI', 'os' => 'IPADOS'],
        ]);

        $this->createProduct($tablets, ProductType::Tablet, ProductCondition::New, [
            'brand' => 'SAMSUNG',
            'model' => 'GALAXY TAB A9+',
            'cost_price' => 5200,
            'sale_price' => 7200,
            'specs' => ['screen' => '11" AMOLED', 'storage' => '128 GB EMMC', 'connectivity' => 'WIFI', 'os' => 'ANDROID'],
        ]);

        $this->createProduct($tablets, ProductType::Tablet, ProductCondition::New, [
            'brand' => 'LENOVO',
            'model' => 'TAB M11',
            'cost_price' => 3800,
            'sale_price' => 5200,
            'specs' => ['screen' => '10.1" FHD', 'storage' => '128 GB EMMC', 'connectivity' => 'WIFI', 'os' => 'ANDROID'],
        ]);

        $this->createProduct($tablets, ProductType::Tablet, ProductCondition::New, [
            'brand' => 'APPLE',
            'model' => 'IPAD AIR M2',
            'cost_price' => 14500,
            'sale_price' => 18900,
            'specs' => ['screen' => '11" RETINA', 'storage' => '256 GB SSD NVME', 'connectivity' => 'WIFI', 'os' => 'IPADOS'],
        ]);

        $this->createProduct($tablets, ProductType::Tablet, ProductCondition::New, [
            'brand' => 'SAMSUNG',
            'model' => 'GALAXY TAB S9 FE',
            'cost_price' => 7800,
            'sale_price' => 10500,
            'specs' => ['screen' => '10.9" RETINA', 'storage' => '128 GB EMMC', 'connectivity' => 'WIFI', 'os' => 'ANDROID'],
        ]);

        // ─── Tablets Usadas (5) ─────────────────────────────────
        $this->createProduct($tablets, ProductType::Tablet, ProductCondition::Used, [
            'brand' => 'APPLE',
            'model' => 'IPAD 8VA GEN',
            'cost_price' => 3500,
            'sale_price' => 5800,
            'specs' => ['screen' => '10.2" RETINA', 'storage' => '32 GB EMMC', 'connectivity' => 'WIFI', 'os' => 'IPADOS'],
        ]);

        $this->createProduct($tablets, ProductType::Tablet, ProductCondition::Used, [
            'brand' => 'SAMSUNG',
            'model' => 'GALAXY TAB A8',
            'cost_price' => 2500,
            'sale_price' => 4200,
            'specs' => ['screen' => '10.5" FHD', 'storage' => '64 GB EMMC', 'connectivity' => 'WIFI', 'os' => 'ANDROID'],
        ]);

        $this->createProduct($tablets, ProductType::Tablet, ProductCondition::Used, [
            'brand' => 'APPLE',
            'model' => 'IPAD 9NA GEN',
            'cost_price' => 4500,
            'sale_price' => 7000,
            'specs' => ['screen' => '10.2" RETINA', 'storage' => '64 GB EMMC', 'connectivity' => 'WIFI', 'os' => 'IPADOS'],
        ]);

        $this->createProduct($tablets, ProductType::Tablet, ProductCondition::Used, [
            'brand' => 'LENOVO',
            'model' => 'TAB M10 PLUS',
            'cost_price' => 2000,
            'sale_price' => 3500,
            'specs' => ['screen' => '10.1" FHD', 'storage' => '64 GB EMMC', 'connectivity' => 'WIFI', 'os' => 'ANDROID'],
        ]);

        $this->createProduct($tablets, ProductType::Tablet, ProductCondition::Used, [
            'brand' => 'SAMSUNG',
            'model' => 'GALAXY TAB S6 LITE',
            'cost_price' => 3200,
            'sale_price' => 5500,
            'specs' => ['screen' => '10.5" FHD', 'storage' => '64 GB EMMC', 'connectivity' => 'WIFI', 'os' => 'ANDROID'],
        ]);

        // ─── Consolas Nuevas (5) ────────────────────────────────
        $consolas = Category::firstOrCreate(
            ['slug' => 'consolas'],
            ['name' => 'Consolas', 'is_active' => true, 'sort_order' => 5]
        );

        $this->createProduct($consolas, ProductType::Console, ProductCondition::New, [
            'brand' => 'SONY',
            'model' => 'PLAYSTATION 5',
            'cost_price' => 12500,
            'sale_price' => 15500,
            'specs' => ['edition' => 'SLIM', 'storage' => '1 TB SSD'],
        ]);

        $this->createProduct($consolas, ProductType::Console, ProductCondition::New, [
            'brand' => 'SONY',
            'model' => 'PLAYSTATION 5 DIGITAL',
            'cost_price' => 10500,
            'sale_price' => 13200,
            'specs' => ['edition' => 'DIGITAL', 'storage' => '1 TB SSD'],
        ]);

        $this->createProduct($consolas, ProductType::Console, ProductCondition::New, [
            'brand' => 'MICROSOFT',
            'model' => 'XBOX SERIES X',
            'cost_price' => 12000,
            'sale_price' => 15000,
            'specs' => ['edition' => 'SERIES X', 'storage' => '1 TB SSD'],
        ]);

        $this->createProduct($consolas, ProductType::Console, ProductCondition::New, [
            'brand' => 'MICROSOFT',
            'model' => 'XBOX SERIES S',
            'cost_price' => 7500,
            'sale_price' => 9500,
            'specs' => ['edition' => 'SERIES S', 'storage' => '512 GB SSD'],
        ]);

        $this->createProduct($consolas, ProductType::Console, ProductCondition::New, [
            'brand' => 'NINTENDO',
            'model' => 'SWITCH OLED',
            'cost_price' => 8500,
            'sale_price' => 10800,
            'specs' => ['edition' => 'OLED', 'storage' => '64 GB EMMC'],
        ]);

        // ─── Consolas Usadas (5) ────────────────────────────────
        $this->createProduct($consolas, ProductType::Console, ProductCondition::Used, [
            'brand' => 'SONY',
            'model' => 'PLAYSTATION 4 PRO',
            'cost_price' => 4500,
            'sale_price' => 7500,
            'specs' => ['edition' => 'PRO', 'storage' => '1 TB HDD'],
        ]);

        $this->createProduct($consolas, ProductType::Console, ProductCondition::Used, [
            'brand' => 'SONY',
            'model' => 'PLAYSTATION 4 SLIM',
            'cost_price' => 3200,
            'sale_price' => 5500,
            'specs' => ['edition' => 'SLIM', 'storage' => '500 GB HDD'],
        ]);

        $this->createProduct($consolas, ProductType::Console, ProductCondition::Used, [
            'brand' => 'MICROSOFT',
            'model' => 'XBOX ONE S',
            'cost_price' => 2800,
            'sale_price' => 4800,
            'specs' => ['edition' => 'STANDARD', 'storage' => '1 TB HDD'],
        ]);

        $this->createProduct($consolas, ProductType::Console, ProductCondition::Used, [
            'brand' => 'NINTENDO',
            'model' => 'SWITCH V2',
            'cost_price' => 4000,
            'sale_price' => 6500,
            'specs' => ['edition' => 'STANDARD', 'storage' => '32 GB EMMC'],
        ]);

        $this->createProduct($consolas, ProductType::Console, ProductCondition::Used, [
            'brand' => 'NINTENDO',
            'model' => 'SWITCH LITE',
            'cost_price' => 2500,
            'sale_price' => 4200,
            'specs' => ['edition' => 'LITE', 'storage' => '32 GB EMMC'],
        ]);

        // ─── Monitores Nuevos (5) ───────────────────────────────
        $monitores = Category::firstOrCreate(
            ['slug' => 'monitores'],
            ['name' => 'Monitores', 'is_active' => true, 'sort_order' => 6]
        );

        $this->createProduct($monitores, ProductType::Monitor, ProductCondition::New, [
            'brand' => 'LG',
            'model' => '24MP60G-B',
            'cost_price' => 3200,
            'sale_price' => 4500,
            'specs' => ['screen' => '24"', 'resolution' => 'FHD 1920X1080', 'panel' => 'IPS', 'refresh' => '75 HZ'],
        ]);

        $this->createProduct($monitores, ProductType::Monitor, ProductCondition::New, [
            'brand' => 'SAMSUNG',
            'model' => 'LS24C330',
            'cost_price' => 2800,
            'sale_price' => 3950,
            'specs' => ['screen' => '24"', 'resolution' => 'FHD 1920X1080', 'panel' => 'IPS', 'refresh' => '100 HZ'],
        ]);

        $this->createProduct($monitores, ProductType::Monitor, ProductCondition::New, [
            'brand' => 'HP',
            'model' => 'P24H G5',
            'cost_price' => 3800,
            'sale_price' => 5200,
            'specs' => ['screen' => '23.8"', 'resolution' => 'FHD 1920X1080', 'panel' => 'IPS', 'refresh' => '75 HZ'],
        ]);

        $this->createProduct($monitores, ProductType::Monitor, ProductCondition::New, [
            'brand' => 'DELL',
            'model' => 'S2722QC',
            'cost_price' => 7500,
            'sale_price' => 9800,
            'specs' => ['screen' => '27"', 'resolution' => '4K 3840X2160', 'panel' => 'IPS', 'refresh' => '60 HZ'],
        ]);

        $this->createProduct($monitores, ProductType::Monitor, ProductCondition::New, [
            'brand' => 'LG',
            'model' => '27GP850-B',
            'cost_price' => 6800,
            'sale_price' => 9200,
            'specs' => ['screen' => '27"', 'resolution' => 'QHD 2560X1440', 'panel' => 'IPS', 'refresh' => '165 HZ'],
        ]);

        // ─── Monitores Usados (5) ───────────────────────────────
        $this->createProduct($monitores, ProductType::Monitor, ProductCondition::Used, [
            'brand' => 'HP',
            'model' => 'E223',
            'cost_price' => 1500,
            'sale_price' => 2800,
            'specs' => ['screen' => '21.5"', 'resolution' => 'FHD 1920X1080', 'panel' => 'IPS', 'refresh' => '60 HZ'],
        ]);

        $this->createProduct($monitores, ProductType::Monitor, ProductCondition::Used, [
            'brand' => 'DELL',
            'model' => 'P2419H',
            'cost_price' => 1800,
            'sale_price' => 3200,
            'specs' => ['screen' => '24"', 'resolution' => 'FHD 1920X1080', 'panel' => 'IPS', 'refresh' => '60 HZ'],
        ]);

        $this->createProduct($monitores, ProductType::Monitor, ProductCondition::Used, [
            'brand' => 'SAMSUNG',
            'model' => 'S24E450',
            'cost_price' => 1200,
            'sale_price' => 2200,
            'specs' => ['screen' => '24"', 'resolution' => 'FHD 1920X1080', 'panel' => 'TN', 'refresh' => '60 HZ'],
        ]);

        $this->createProduct($monitores, ProductType::Monitor, ProductCondition::Used, [
            'brand' => 'LG',
            'model' => '22MK430H',
            'cost_price' => 1000,
            'sale_price' => 1800,
            'specs' => ['screen' => '21.5"', 'resolution' => 'FHD 1920X1080', 'panel' => 'IPS', 'refresh' => '75 HZ'],
        ]);

        $this->createProduct($monitores, ProductType::Monitor, ProductCondition::Used, [
            'brand' => 'HP',
            'model' => 'V194',
            'cost_price' => 700,
            'sale_price' => 1400,
            'specs' => ['screen' => '19"', 'resolution' => 'HD 1366X768', 'panel' => 'TN', 'refresh' => '60 HZ'],
        ]);

        // ─── Impresoras Nuevas (5) ──────────────────────────────
        $impresoras = Category::firstOrCreate(
            ['slug' => 'impresoras'],
            ['name' => 'Impresoras', 'is_active' => true, 'sort_order' => 7]
        );

        $this->createProduct($impresoras, ProductType::Printer, ProductCondition::New, [
            'brand' => 'EPSON',
            'model' => 'L3250',
            'cost_price' => 4200,
            'sale_price' => 5800,
            'specs' => ['printer_type' => 'INYECCION DE TINTA', 'printer_functions' => 'IMPRESION + COPIA + ESCANER', 'printer_conn' => 'USB + WIFI'],
        ]);

        $this->createProduct($impresoras, ProductType::Printer, ProductCondition::New, [
            'brand' => 'HP',
            'model' => 'LASERJET PRO M404DW',
            'cost_price' => 6500,
            'sale_price' => 8800,
            'specs' => ['printer_type' => 'LASER', 'printer_functions' => 'IMPRESION', 'printer_conn' => 'USB + WIFI + ETHERNET'],
        ]);

        $this->createProduct($impresoras, ProductType::Printer, ProductCondition::New, [
            'brand' => 'EPSON',
            'model' => 'L5290',
            'cost_price' => 5800,
            'sale_price' => 7800,
            'specs' => ['printer_type' => 'INYECCION DE TINTA', 'printer_functions' => 'MULTIFUNCION (TODO)', 'printer_conn' => 'USB + WIFI + ETHERNET'],
        ]);

        $this->createProduct($impresoras, ProductType::Printer, ProductCondition::New, [
            'brand' => 'EPSON',
            'model' => 'TM-T20III',
            'cost_price' => 3500,
            'sale_price' => 4800,
            'specs' => ['printer_type' => 'TERMICA 80MM', 'printer_functions' => 'IMPRESION', 'printer_conn' => 'USB + ETHERNET'],
        ]);

        $this->createProduct($impresoras, ProductType::Printer, ProductCondition::New, [
            'brand' => 'HP',
            'model' => 'SMART TANK 580',
            'cost_price' => 4500,
            'sale_price' => 6200,
            'specs' => ['printer_type' => 'INYECCION DE TINTA', 'printer_functions' => 'IMPRESION + COPIA + ESCANER', 'printer_conn' => 'USB + WIFI'],
        ]);

        // ─── Impresoras Usadas (5) ──────────────────────────────
        $this->createProduct($impresoras, ProductType::Printer, ProductCondition::Used, [
            'brand' => 'EPSON',
            'model' => 'L210',
            'cost_price' => 1200,
            'sale_price' => 2200,
            'specs' => ['printer_type' => 'INYECCION DE TINTA', 'printer_functions' => 'IMPRESION + COPIA + ESCANER', 'printer_conn' => 'SOLO USB'],
        ]);

        $this->createProduct($impresoras, ProductType::Printer, ProductCondition::Used, [
            'brand' => 'HP',
            'model' => 'LASERJET PRO M402N',
            'cost_price' => 2500,
            'sale_price' => 4200,
            'specs' => ['printer_type' => 'LASER', 'printer_functions' => 'IMPRESION', 'printer_conn' => 'USB + ETHERNET'],
        ]);

        $this->createProduct($impresoras, ProductType::Printer, ProductCondition::Used, [
            'brand' => 'EPSON',
            'model' => 'L380',
            'cost_price' => 1500,
            'sale_price' => 2800,
            'specs' => ['printer_type' => 'INYECCION DE TINTA', 'printer_functions' => 'IMPRESION + COPIA + ESCANER', 'printer_conn' => 'SOLO USB'],
        ]);

        $this->createProduct($impresoras, ProductType::Printer, ProductCondition::Used, [
            'brand' => 'EPSON',
            'model' => 'FX-890II',
            'cost_price' => 3000,
            'sale_price' => 5000,
            'specs' => ['printer_type' => 'MATRIZ DE PUNTO', 'printer_functions' => 'IMPRESION', 'printer_conn' => 'SOLO USB'],
        ]);

        $this->createProduct($impresoras, ProductType::Printer, ProductCondition::Used, [
            'brand' => 'HP',
            'model' => 'LASERJET PRO MFP M428FDW',
            'cost_price' => 3500,
            'sale_price' => 5800,
            'specs' => ['printer_type' => 'LASER', 'printer_functions' => 'MULTIFUNCION (TODO)', 'printer_conn' => 'USB + WIFI + ETHERNET'],
        ]);

        // ─── Componentes Nuevos (5) ─────────────────────────────
        $componentes = Category::firstOrCreate(
            ['slug' => 'componentes'],
            ['name' => 'Componentes', 'is_active' => true, 'sort_order' => 8]
        );

        $this->createProduct($componentes, ProductType::Component, ProductCondition::New, [
            'brand' => 'KINGSTON',
            'model' => 'FURY BEAST DDR4',
            'cost_price' => 550,
            'sale_price' => 850,
            'specs' => ['component_type' => 'MEMORIA RAM', 'capacity' => '8 GB', 'comp_speed' => '3200 MHZ', 'comp_interface' => 'DDR4'],
        ]);

        $this->createProduct($componentes, ProductType::Component, ProductCondition::New, [
            'brand' => 'KINGSTON',
            'model' => 'NV2',
            'cost_price' => 750,
            'sale_price' => 1100,
            'specs' => ['component_type' => 'SSD NVME M.2', 'capacity' => '512 GB', 'comp_speed' => '3500 MB/S', 'comp_interface' => 'NVME M.2'],
        ]);

        $this->createProduct($componentes, ProductType::Component, ProductCondition::New, [
            'brand' => 'CRUCIAL',
            'model' => 'BX500',
            'cost_price' => 480,
            'sale_price' => 750,
            'specs' => ['component_type' => 'SSD SATA', 'capacity' => '480 GB', 'comp_speed' => '540 MB/S', 'comp_interface' => 'SATA III'],
        ]);

        $this->createProduct($componentes, ProductType::Component, ProductCondition::New, [
            'brand' => 'SEAGATE',
            'model' => 'BARRACUDA',
            'cost_price' => 850,
            'sale_price' => 1250,
            'specs' => ['component_type' => 'HDD', 'capacity' => '1 TB', 'comp_speed' => '7200 RPM', 'comp_interface' => 'SATA III'],
        ]);

        $this->createProduct($componentes, ProductType::Component, ProductCondition::New, [
            'brand' => 'CORSAIR',
            'model' => 'VENGEANCE DDR5',
            'cost_price' => 1200,
            'sale_price' => 1750,
            'specs' => ['component_type' => 'MEMORIA RAM', 'capacity' => '16 GB', 'comp_speed' => '5200 MHZ', 'comp_interface' => 'DDR5'],
        ]);

        // ─── Componentes Usados (5) ─────────────────────────────
        $this->createProduct($componentes, ProductType::Component, ProductCondition::Used, [
            'brand' => 'SAMSUNG',
            'model' => '860 EVO',
            'cost_price' => 300,
            'sale_price' => 550,
            'specs' => ['component_type' => 'SSD SATA', 'capacity' => '250 GB', 'comp_speed' => '550 MB/S', 'comp_interface' => 'SATA III'],
        ]);

        $this->createProduct($componentes, ProductType::Component, ProductCondition::Used, [
            'brand' => 'KINGSTON',
            'model' => 'VALUERAM DDR4',
            'cost_price' => 250,
            'sale_price' => 450,
            'specs' => ['component_type' => 'MEMORIA RAM', 'capacity' => '4 GB', 'comp_speed' => '2666 MHZ', 'comp_interface' => 'DDR4'],
        ]);

        $this->createProduct($componentes, ProductType::Component, ProductCondition::Used, [
            'brand' => 'WD',
            'model' => 'BLUE 1TB',
            'cost_price' => 350,
            'sale_price' => 600,
            'specs' => ['component_type' => 'HDD', 'capacity' => '1 TB', 'comp_speed' => '7200 RPM', 'comp_interface' => 'SATA III'],
        ]);

        $this->createProduct($componentes, ProductType::Component, ProductCondition::Used, [
            'brand' => 'KINGSTON',
            'model' => 'A400',
            'cost_price' => 200,
            'sale_price' => 380,
            'specs' => ['component_type' => 'SSD SATA', 'capacity' => '120 GB', 'comp_speed' => '500 MB/S', 'comp_interface' => 'SATA III'],
        ]);

        $this->createProduct($componentes, ProductType::Component, ProductCondition::Used, [
            'brand' => 'CRUCIAL',
            'model' => 'DDR3 DESKTOP',
            'cost_price' => 150,
            'sale_price' => 300,
            'specs' => ['component_type' => 'MEMORIA RAM', 'capacity' => '4 GB', 'comp_speed' => '1600 MHZ', 'comp_interface' => 'DDR3'],
        ]);

        $this->command->info("✅ 80 productos creados (8 tipos × 10 cada uno: 5 nuevos + 5 usados)");
    }

    /**
     * Crear un producto con stock 5 y min_stock 2.
     */
    private function createProduct(
        Category $category,
        ProductType $type,
        ProductCondition $condition,
        array $data
    ): Product {
        return Product::create([
            'category_id' => $category->id,
            'product_type' => $type,
            'condition' => $condition,
            'brand' => $data['brand'],
            'model' => $data['model'],
            'cost_price' => $data['cost_price'],
            'sale_price' => $data['sale_price'],
            'stock' => 5,
            'min_stock' => 2,
            'specs' => array_filter($data['specs'] ?? []),
            'is_active' => true,
        ]);
    }
}
