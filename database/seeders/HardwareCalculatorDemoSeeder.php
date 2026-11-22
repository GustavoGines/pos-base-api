<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Product;
use App\Services\BarcodeService;
use Illuminate\Database\Seeder;

class HardwareCalculatorDemoSeeder extends Seeder
{
    public function run(): void
    {
        // Asegurar entorno Ferretería activo en BD local para pruebas de Calculadora F7 y Presupuestos
        BusinessSetting::updateOrCreate(
            ['key' => 'license_business_type'],
            ['value' => 'hardware_store']
        );

        if (BusinessSetting::where('key', 'license_integrity_hash')->doesntExist()) {
            $featuresRaw = BusinessSetting::where('key', 'license_features_dict')->value('value');
            $features = ! empty($featuresRaw) ? (json_decode($featuresRaw, true) ?: []) : [];
            $features['quotes'] = true;
            $features['multiple_prices'] = true;
            $features['logistics'] = true;
            BusinessSetting::updateOrCreate(
                ['key' => 'license_features_dict'],
                ['value' => json_encode($features)]
            );
        }

        $catMaderas = Category::firstOrCreate(['name' => 'Maderas y Machimbres']);
        $catChapas = Category::firstOrCreate(['name' => 'Chapas y Techos']);
        $catPintura = Category::firstOrCreate(['name' => 'Pinturería y Membranas']);
        $catFijaciones = Category::firstOrCreate(['name' => 'Fijaciones y Complementos']);

        $brandPino = Brand::firstOrCreate(['name' => 'Alto Paraná']);
        $brandTernium = Brand::firstOrCreate(['name' => 'Ternium Siderar']);
        $brandAlba = Brand::firstOrCreate(['name' => 'Alba']);
        $brandPlavicon = Brand::firstOrCreate(['name' => 'Plavicon']);
        $brandTel = Brand::firstOrCreate(['name' => 'TEL Fijaciones']);

        $barcodeService = app(BarcodeService::class);

        $items = [
            // ── 1. MACHIMBRES Y MADERAS (4 modalidades de precio + 1 sin calc_config para auto-detección) ──
            [
                'internal_code' => 'HW101',
                'name' => 'Machimbre Pino 1/2" x 4" x 3.00m (x Tabla)',
                'category_id' => $catMaderas->id,
                'brand_id' => $brandPino->id,
                'cost_price' => 2100.00,
                'selling_price' => 3450.00,
                'stock' => 350.000,
                'min_stock' => 40,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                'calc_config' => [
                    'type' => 'wood',
                    'inch_preset' => '4',
                    'nominal_width_m' => 0.1016,
                    'useful_width_m' => 0.088,
                    'length_m' => 3.00,
                    'pricing_mode' => 'per_board',
                    'default_waste_pct' => 10,
                ],
            ],
            [
                'internal_code' => 'HW102',
                'name' => 'Machimbre Pino 1/2" x 5" x 3.60m (x m²)',
                'category_id' => $catMaderas->id,
                'brand_id' => $brandPino->id,
                'cost_price' => 6800.00,
                'selling_price' => 10900.00,
                'stock' => 280.000,
                'min_stock' => 30,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                'calc_config' => [
                    'type' => 'wood',
                    'inch_preset' => '5',
                    'nominal_width_m' => 0.127,
                    'useful_width_m' => 0.112,
                    'length_m' => 3.60,
                    'pricing_mode' => 'per_m2',
                    'm2_billing_basis' => 'nominal',
                    'default_waste_pct' => 10,
                ],
            ],
            [
                'internal_code' => 'HW103',
                'name' => 'Machimbre Pino 1/2" x 4" x 2.40m (Paquete x 10)',
                'category_id' => $catMaderas->id,
                'brand_id' => $brandPino->id,
                'cost_price' => 17500.00,
                'selling_price' => 27900.00,
                'stock' => 65.000,
                'min_stock' => 10,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                'calc_config' => [
                    'type' => 'wood',
                    'inch_preset' => '4',
                    'nominal_width_m' => 0.1016,
                    'useful_width_m' => 0.088,
                    'length_m' => 2.40,
                    'pricing_mode' => 'per_pack',
                    'boards_per_pack' => 10,
                    'round_to_full_pack' => true,
                    'default_waste_pct' => 5,
                ],
            ],
            [
                'internal_code' => 'HW104',
                'name' => 'Machimbre Eucalipto 3/4" x 6" x 4.00m (x Metro Lineal)',
                'category_id' => $catMaderas->id,
                'brand_id' => $brandPino->id,
                'cost_price' => 1450.00,
                'selling_price' => 2350.00,
                'stock' => 600.000,
                'min_stock' => 80,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                'calc_config' => [
                    'type' => 'wood',
                    'inch_preset' => '6',
                    'nominal_width_m' => 0.1524,
                    'useful_width_m' => 0.137,
                    'length_m' => 4.00,
                    'pricing_mode' => 'per_linear_meter',
                    'default_waste_pct' => 10,
                ],
            ],
            [
                'internal_code' => 'HW105',
                'name' => 'Deck Pino Impregnado 1" x 4" x 3.00m',
                'category_id' => $catMaderas->id,
                'brand_id' => $brandPino->id,
                'cost_price' => 3800.00,
                'selling_price' => 6200.00,
                'stock' => 180.000,
                'min_stock' => 20,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                // Sin calc_config a propósito para probar Auto-Detector + PATCH /calc-config
                'calc_config' => null,
            ],

            // ── 2. CHAPAS Y TECHOS ──
            [
                'internal_code' => 'HW201',
                'name' => 'Chapa Acanalada Galvanizada C27 1.10 x 4.50m',
                'category_id' => $catChapas->id,
                'brand_id' => $brandTernium->id,
                'cost_price' => 28500.00,
                'selling_price' => 42900.00,
                'stock' => 85.000,
                'min_stock' => 10,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                'calc_config' => [
                    'type' => 'roof',
                    'nominal_width_m' => 1.10,
                    'useful_width_m' => 1.00,
                    'length_m' => 4.50,
                    'longitudinal_overlap_m' => 0.20,
                    'pricing_mode' => 'per_sheet',
                ],
            ],
            [
                'internal_code' => 'HW202',
                'name' => 'Chapa Trapezoidal C25 Prepintada Negra 1.10 x 6.00m',
                'category_id' => $catChapas->id,
                'brand_id' => $brandTernium->id,
                    'cost_price' => 46000.00,
                'selling_price' => 68500.00,
                'stock' => 40.000,
                'min_stock' => 6,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                'calc_config' => [
                    'type' => 'roof',
                    'nominal_width_m' => 1.10,
                    'useful_width_m' => 1.00,
                    'length_m' => 6.00,
                    'longitudinal_overlap_m' => 0.20,
                    'pricing_mode' => 'per_sheet',
                ],
            ],
            [
                'internal_code' => 'HW203',
                'name' => 'Chapa Acanalada C27 Aluminizada (x Metro Lineal)',
                'category_id' => $catChapas->id,
                'brand_id' => $brandTernium->id,
                'cost_price' => 6400.00,
                'selling_price' => 9800.00,
                'stock' => 320.000,
                'min_stock' => 30,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                'calc_config' => [
                    'type' => 'roof',
                    'nominal_width_m' => 1.10,
                    'useful_width_m' => 1.00,
                    'length_m' => 3.50,
                    'longitudinal_overlap_m' => 0.20,
                    'pricing_mode' => 'per_linear_meter',
                ],
            ],

            // ── 3. PINTURA Y MEMBRANA LÍQUIDA ──
            [
                'internal_code' => 'HW301',
                'name' => 'Pintura Látex Interior Mate Alba 4L',
                'category_id' => $catPintura->id,
                'brand_id' => $brandAlba->id,
                'cost_price' => 14500.00,
                'selling_price' => 22900.00,
                'stock' => 45.000,
                'min_stock' => 6,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                'calc_config' => [
                    'type' => 'paint',
                    'yield_m2_per_liter' => 10.0,
                    'yield_mode' => 'per_coat',
                    'container_liters' => 4.0,
                    'default_coats' => 2,
                ],
            ],
            [
                'internal_code' => 'HW302',
                'name' => 'Pintura Látex Exterior Lavable Alba 20L',
                'category_id' => $catPintura->id,
                'brand_id' => $brandAlba->id,
                'cost_price' => 58000.00,
                'selling_price' => 89500.00,
                'stock' => 22.000,
                'min_stock' => 4,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                'calc_config' => [
                    'type' => 'paint',
                    'yield_m2_per_liter' => 10.0,
                    'yield_mode' => 'per_coat',
                    'container_liters' => 20.0,
                    'default_coats' => 2,
                ],
            ],
            [
                'internal_code' => 'HW303',
                'name' => 'Esmalte Sintético Blanco Brillante 1L',
                'category_id' => $catPintura->id,
                'brand_id' => $brandAlba->id,
                'cost_price' => 6200.00,
                'selling_price' => 9900.00,
                'stock' => 60.000,
                'min_stock' => 8,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                'calc_config' => [
                    'type' => 'paint',
                    'yield_m2_per_liter' => 12.0,
                    'yield_mode' => 'per_coat',
                    'container_liters' => 1.0,
                    'default_coats' => 2,
                ],
            ],
            [
                'internal_code' => 'HW304',
                'name' => 'Membrana Líquida Fibrada Plavicon Techos 20Kg',
                'category_id' => $catPintura->id,
                'brand_id' => $brandPlavicon->id,
                'cost_price' => 64000.00,
                'selling_price' => 96500.00,
                'stock' => 18.000,
                'min_stock' => 3,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                'calc_config' => [
                    'type' => 'paint',
                    'yield_m2_per_liter' => 1.0,
                    'yield_mode' => 'final_total',
                    'container_liters' => 20.0,
                    'default_coats' => 3,
                ],
            ],
            [
                'internal_code' => 'HW305',
                'name' => 'Impermeabilizante Acrílico Frente 10L',
                'category_id' => $catPintura->id,
                'brand_id' => $brandPlavicon->id,
                'cost_price' => 31000.00,
                'selling_price' => 48000.00,
                'stock' => 25.000,
                'min_stock' => 4,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                // Sin calc_config a propósito para probar Auto-Detector
                'calc_config' => null,
            ],

            // ── 4. COMPLEMENTOS SUGERIDOS POR LA CALCULADORA ──
            [
                'internal_code' => 'HW401',
                'name' => 'Tirante Pino Cepillado 2" x 2" x 3.00m (Alfajía)',
                'category_id' => $catMaderas->id,
                'brand_id' => $brandPino->id,
                'cost_price' => 2400.00,
                'selling_price' => 3900.00,
                'stock' => 210.000,
                'min_stock' => 25,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                'calc_config' => null,
            ],
            [
                'internal_code' => 'HW402',
                'name' => 'Clavo Espiralado p/ Machimbre 1 1/2" (x Kg)',
                'category_id' => $catFijaciones->id,
                'brand_id' => $brandTel->id,
                'cost_price' => 3200.00,
                'selling_price' => 5400.00,
                'stock' => 45.000,
                'min_stock' => 5,
                'unit_type' => 'kg',
                'is_sold_by_weight' => true,
                'calc_config' => null,
            ],
            [
                'internal_code' => 'HW403',
                'name' => 'Tornillo Autoperforante Hexagonal 14x2" c/ Arandela (x 100u)',
                'category_id' => $catFijaciones->id,
                'brand_id' => $brandTel->id,
                'cost_price' => 5500.00,
                'selling_price' => 8900.00,
                'stock' => 90.000,
                'min_stock' => 10,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                'calc_config' => null,
            ],
            [
                'internal_code' => 'HW404',
                'name' => 'Perfil C Galvanizado 100x50x15x2mm x 6.00m',
                'category_id' => $catChapas->id,
                'brand_id' => $brandTernium->id,
                'cost_price' => 24500.00,
                'selling_price' => 37800.00,
                'stock' => 50.000,
                'min_stock' => 8,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                'calc_config' => null,
            ],
            [
                'internal_code' => 'HW405',
                'name' => 'Rodillo Antigota Lana Sintética 22cm',
                'category_id' => $catPintura->id,
                'brand_id' => $brandAlba->id,
                'cost_price' => 3900.00,
                'selling_price' => 6500.00,
                'stock' => 35.000,
                'min_stock' => 5,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                'calc_config' => null,
            ],
            [
                'internal_code' => 'HW406',
                'name' => 'Cinta de Enmascarar Pintor 24mm x 50m',
                'category_id' => $catPintura->id,
                'brand_id' => $brandAlba->id,
                'cost_price' => 1400.00,
                'selling_price' => 2450.00,
                'stock' => 80.000,
                'min_stock' => 12,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                'calc_config' => null,
            ],
            [
                'internal_code' => 'HW407',
                'name' => 'Venda Geotextil p/ Membrana 20cm x 25m',
                'category_id' => $catPintura->id,
                'brand_id' => $brandPlavicon->id,
                'cost_price' => 7800.00,
                'selling_price' => 12500.00,
                'stock' => 24.000,
                'min_stock' => 4,
                'unit_type' => 'un',
                'is_sold_by_weight' => false,
                'calc_config' => null,
            ],
        ];

        foreach ($items as $row) {
            $existing = Product::where('internal_code', $row['internal_code'])->first();
            $barcode = $existing?->barcode;
            if (empty($barcode) && empty($row['is_sold_by_weight'])) {
                $barcode = $barcodeService->generateInternalEan13($row['internal_code']);
            }

            Product::updateOrCreate(
                ['internal_code' => $row['internal_code']],
                [
                    'name' => $row['name'],
                    'barcode' => $barcode,
                    'category_id' => $row['category_id'],
                    'brand_id' => $row['brand_id'],
                    'cost_price' => $row['cost_price'],
                    'selling_price' => $row['selling_price'],
                    'price_wholesale' => round($row['selling_price'] * 0.88, 2),
                    'price_card' => round($row['selling_price'] * 1.15, 2),
                    'iva_rate' => 21.00,
                    'stock' => $row['stock'],
                    'min_stock' => $row['min_stock'],
                    'unit_type' => $row['unit_type'],
                    'is_sold_by_weight' => $row['is_sold_by_weight'],
                    'active' => true,
                    'needs_catalog_review' => false,
                    'calc_config' => $row['calc_config'],
                ]
            );
        }
    }
}
