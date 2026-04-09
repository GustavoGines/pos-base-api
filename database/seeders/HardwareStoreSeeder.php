<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;
use App\Models\Category;

class HardwareStoreSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Crear algunas categorías base si no existen
        $catConstruccion = Category::firstOrCreate(['name' => 'Materiales de Construcción']);
        $catMaderas = Category::firstOrCreate(['name' => 'Maderas y Placas']);
        $catFerreteria = Category::firstOrCreate(['name' => 'Ferretería General']);
        $catElectrico = Category::firstOrCreate(['name' => 'Materiales Eléctricos']);

        // 2. Definir los productos realistas solicitados
        $products = [
            [
                'name' => 'Cemento Loma Negra 50kg',
                'barcode' => '7791234567890',
                'category_id' => $catConstruccion->id,
                'selling_price' => 8500,
                'min_stock' => 50,
                'stock' => 200,
                'unit_type' => 'un',
            ],
            [
                'name' => 'Tirante Pino 2x4 (por metro)',
                'barcode' => 'TIRP2X4M',
                'category_id' => $catMaderas->id,
                'selling_price' => 3200,
                'min_stock' => 100,
                'stock' => 500,
                'unit_type' => 'un',
            ],
            [
                'name' => 'Placa Melamina Faplac Blanca 18mm (1.83x2.60)',
                'barcode' => 'MELAFAP18BL',
                'category_id' => $catMaderas->id,
                'selling_price' => 45000,
                'min_stock' => 10,
                'stock' => 35,
                'unit_type' => 'un',
            ],
            [
                'name' => 'Corte Melamina Blanca 18mm',
                'barcode' => 'MELAFAP18BLC',
                'category_id' => $catMaderas->id,
                'selling_price' => 12000,
                'min_stock' => 0,
                'stock' => 100,
                'unit_type' => 'un',
            ],
            [
                'name' => 'Tornillos T2 Punta Aguja',
                'barcode' => '2000000000000', // Típico PLU interno para venta por peso
                'category_id' => $catFerreteria->id,
                'selling_price' => 6000, // Precio por Kg
                'min_stock' => 5,
                'stock' => 25,
                'unit_type' => 'kg',
            ],
            [
                'name' => 'Cable Sintenax 2x2.5mm IRAM',
                'barcode' => 'CABSIN2X25',
                'category_id' => $catElectrico->id,
                'selling_price' => 1500,
                'min_stock' => 50,
                'stock' => 300,
                'unit_type' => 'un',
            ],
            [
                'name' => 'Hierro Aletado Construcción 8mm',
                'barcode' => 'HIEALE8MM',
                'category_id' => $catConstruccion->id,
                'selling_price' => 6800,
                'min_stock' => 100,
                'stock' => 450,
                'unit_type' => 'un', // Se vende por unidad (varilla de 12m)
            ],
            [
                'name' => 'Clavos Punta París 2 1/2"',
                'barcode' => '2000000000001',
                'category_id' => $catFerreteria->id,
                'selling_price' => 2500, // Precio por Kg
                'min_stock' => 10,
                'stock' => 60,
                'unit_type' => 'kg',
            ],
            [
                'name' => 'Pintura Látex Interior Alba 20L',
                'barcode' => '7799876543210',
                'category_id' => $catFerreteria->id,
                'selling_price' => 55000,
                'min_stock' => 5,
                'stock' => 20,
                'unit_type' => 'un',
            ],
            [
                'name' => 'Arena Fina (Bolsón)',
                'barcode' => 'AREFINBOL',
                'category_id' => $catConstruccion->id,
                'selling_price' => 18000,
                'min_stock' => 20,
                'stock' => 80,
                'unit_type' => 'un', // Se vende por viaje/un bolsón
            ],
        ];

        // 3. Iterar, calcular precios y guardar
        foreach ($products as $data) {
            $basePrice = $data['selling_price'];
            
            // Mayorista: 10% más barato
            $data['price_wholesale'] = round($basePrice * 0.90, 2);
            
            // Tarjeta: 15% más caro (sobre el precio base)
            $data['price_card'] = round($basePrice * 1.15, 2);

            // Código interno obligatorio (uso el barcode o un hash corto como fallback)
            $data['internal_code'] = $data['barcode'];

            Product::updateOrCreate(
                ['barcode' => $data['barcode']], // Evitar duplicados si se corre 2 veces
                $data
            );
        }

        $this->command->info('✨ Hardware Store Seeder finalizado. ¡10 productos añadidos con sus respectivos precios (T / M)!');
    }
}
