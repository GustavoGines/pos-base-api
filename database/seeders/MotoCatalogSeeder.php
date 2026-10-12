<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Rubro;
use Illuminate\Database\Seeder;

class MotoCatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Rubro y Categoría
        $rubro = Rubro::firstOrCreate(
            ['name' => 'Motos y Rodados'],
            ['is_system' => false]
        );

        $category = Category::firstOrCreate(
            ['name' => 'Motos'],
            [
                'rubro_id' => $rubro->id,
                'description' => 'Motocicletas, ciclomotores y rodados',
            ]
        );

        // Si la categoría ya existía pero sin rubro_id, se actualiza
        if (!$category->rubro_id) {
            $category->update(['rubro_id' => $rubro->id]);
        }

        // 2. Definición de las 10 motos
        $motos = [
            [
                'name' => 'Honda Wave 110S',
                'brand' => 'Honda',
                'barcode' => '7799000100011',
                'internal_code' => 'MOTO-001',
                'cost_price' => 2100000.00,
                'selling_price' => 2750000.00,
                'price_wholesale' => 2500000.00,
                'price_card' => 3100000.00,
                'stock' => 5,
                'min_stock' => 1,
            ],
            [
                'name' => 'Yamaha YBR 125 Z',
                'brand' => 'Yamaha',
                'barcode' => '7799000100028',
                'internal_code' => 'MOTO-002',
                'cost_price' => 2650000.00,
                'selling_price' => 3450000.00,
                'price_wholesale' => 3150000.00,
                'price_card' => 3890000.00,
                'stock' => 4,
                'min_stock' => 1,
            ],
            [
                'name' => 'Motomel B110 Blitz',
                'brand' => 'Motomel',
                'barcode' => '7799000100035',
                'internal_code' => 'MOTO-003',
                'cost_price' => 1250000.00,
                'selling_price' => 1650000.00,
                'price_wholesale' => 1490000.00,
                'price_card' => 1870000.00,
                'stock' => 8,
                'min_stock' => 2,
            ],
            [
                'name' => 'Gilera Smash 110 Full',
                'brand' => 'Gilera',
                'barcode' => '7799000100042',
                'internal_code' => 'MOTO-004',
                'cost_price' => 1200000.00,
                'selling_price' => 1590000.00,
                'price_wholesale' => 1450000.00,
                'price_card' => 1790000.00,
                'stock' => 10,
                'min_stock' => 2,
            ],
            [
                'name' => 'Zanella ZB 110 RT',
                'brand' => 'Zanella',
                'barcode' => '7799000100059',
                'internal_code' => 'MOTO-005',
                'cost_price' => 1300000.00,
                'selling_price' => 1720000.00,
                'price_wholesale' => 1560000.00,
                'price_card' => 1940000.00,
                'stock' => 6,
                'min_stock' => 2,
            ],
            [
                'name' => 'Honda XR 150L',
                'brand' => 'Honda',
                'barcode' => '7799000100066',
                'internal_code' => 'MOTO-006',
                'cost_price' => 3800000.00,
                'selling_price' => 4950000.00,
                'price_wholesale' => 4550000.00,
                'price_card' => 5590000.00,
                'stock' => 3,
                'min_stock' => 1,
            ],
            [
                'name' => 'Yamaha FZ-S FI V3.0',
                'brand' => 'Yamaha',
                'barcode' => '7799000100073',
                'internal_code' => 'MOTO-007',
                'cost_price' => 4200000.00,
                'selling_price' => 5500000.00,
                'price_wholesale' => 5050000.00,
                'price_card' => 6200000.00,
                'stock' => 3,
                'min_stock' => 1,
            ],
            [
                'name' => 'Bajaj Rouser NS 200',
                'brand' => 'Bajaj',
                'barcode' => '7799000100080',
                'internal_code' => 'MOTO-008',
                'cost_price' => 3900000.00,
                'selling_price' => 5100000.00,
                'price_wholesale' => 4700000.00,
                'price_card' => 5750000.00,
                'stock' => 4,
                'min_stock' => 1,
            ],
            [
                'name' => 'Corven Triax 150 R3',
                'brand' => 'Corven',
                'barcode' => '7799000100097',
                'internal_code' => 'MOTO-009',
                'cost_price' => 2100000.00,
                'selling_price' => 2790000.00,
                'price_wholesale' => 2550000.00,
                'price_card' => 3150000.00,
                'stock' => 5,
                'min_stock' => 1,
            ],
            [
                'name' => 'Benelli TNT 150i',
                'brand' => 'Benelli',
                'barcode' => '7799000100103',
                'internal_code' => 'MOTO-010',
                'cost_price' => 3100000.00,
                'selling_price' => 4050000.00,
                'price_wholesale' => 3700000.00,
                'price_card' => 4580000.00,
                'stock' => 2,
                'min_stock' => 1,
            ],
        ];

        // 3. Inserción / Actualización segura
        $insertedCount = 0;
        foreach ($motos as $item) {
            $brand = Brand::firstOrCreate(['name' => $item['brand']]);

            Product::updateOrCreate(
                ['barcode' => $item['barcode']],
                [
                    'name' => $item['name'],
                    'internal_code' => $item['internal_code'],
                    'cost_price' => $item['cost_price'],
                    'selling_price' => $item['selling_price'],
                    'price_wholesale' => $item['price_wholesale'],
                    'price_card' => $item['price_card'],
                    'iva_rate' => 21.00,
                    'stock' => $item['stock'],
                    'min_stock' => $item['min_stock'],
                    'active' => true,
                    'is_combo' => false,
                    'is_sold_by_weight' => false,
                    'unit_type' => 'un',
                    'category_id' => $category->id,
                    'brand_id' => $brand->id,
                ]
            );
            $insertedCount++;
        }

        $this->command->info("🏍️ Se cargaron exitosamente {$insertedCount} motos en la base de datos.");
    }
}
