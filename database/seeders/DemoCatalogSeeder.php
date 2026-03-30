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
            'internal_code' => '00001', 
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
            'internal_code' => '00002', 
            'cost_price' => 300.00,
            'selling_price' => 550.00,
            'stock' => 20,
            'active' => true,
            'is_sold_by_weight' => false,
            'category_id' => $catInsumos->id,
        ]);

        // 3. Crear Producto de Balanza
        Product::create([
            'name' => 'Harina 0000 (Suelto Kg)',
            'barcode' => null,
            'internal_code' => '00003',
            'cost_price' => 250.00,
            'selling_price' => 450.00,
            'stock' => 100.5,
            'active' => true,
            'is_sold_by_weight' => true,
            'category_id' => $catInsumos->id,
        ]);
    }
}
