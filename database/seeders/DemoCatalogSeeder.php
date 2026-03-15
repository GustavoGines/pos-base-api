<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Str;

class DemoCatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // 1. Crear Categorías
        $catBebidas = Category::create([
            'name' => 'Bebidas',
            'description' => 'Gaseosas, aguas y jugos'
        ]);

        $catInsumos = Category::create([
            'name' => 'Insumos Pastelería',
            'description' => 'Harinas, azúcares y repostería general'
        ]);

        // 2. Crear Productos Unitarios con Barcode Ficticio
        Product::create([
            'name' => 'Coca Cola 1.5L',
            'barcode' => '7791234567890',
            'internal_code' => '100000000001', // Código manual demo, en la app real lo autogenera el Controller si viene vacío
            'cost_price' => 800.00,
            'selling_price' => 1200.00,
            'stock' => 50,
            'active' => true,
            'is_sold_by_weight' => false,
            'category_id' => $catBebidas->id,
        ]);

        Product::create([
            'name' => 'Esencia de Vainilla 100ml',
            'barcode' => '7799876543210',
            'internal_code' => '100000000002', 
            'cost_price' => 300.00,
            'selling_price' => 550.00,
            'stock' => 20,
            'active' => true,
            'is_sold_by_weight' => false,
            'category_id' => $catInsumos->id,
        ]);

        // 3. Crear Producto de Balanza SIN Barcode (para forzar al cajero/backend a usar código interno)
        // Simulamos la generación del Internal Code EAN-13 que haría The Controller
        $prefix = "200";
        $body = str_pad((string) rand(1, 999999999), 9, '0', STR_PAD_LEFT);
        $ean12 = $prefix . $body;
        $checksum = $this->calculateEan13Checksum($ean12);
        
        Product::create([
            'name' => 'Harina 0000 (Suelto Kg)',
            'barcode' => null, // Esto es clave
            'internal_code' => $ean12 . $checksum,
            'cost_price' => 250.00,
            'selling_price' => 450.00,
            'stock' => 100.5, // Expresado en Kg
            'active' => true,
            'is_sold_by_weight' => true,
            'category_id' => $catInsumos->id,
        ]);
    }

    private function calculateEan13Checksum(string $ean12): int
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $ean12[$i];
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }
        $remainder = $sum % 10;
        return $remainder === 0 ? 0 : 10 - $remainder;
    }
}
