<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Product;

/**
 * BigCatalogSeeder - 100 productos en 8 categorías
 * Pensado para un almacén/rotisería/dietética argentina.
 * Incluye productos unitarios y a granel (is_sold_by_weight).
 *
 * Uso: php artisan db:seed --class=BigCatalogSeeder
 *   o agregarlo en DatabaseSeeder para que corra en cada migrate:fresh --seed
 */
class BigCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // ─────────────────────────────────────────────────────────────
        // 1. Categorías
        // ─────────────────────────────────────────────────────────────
        $cats = [];
        $catDefs = [
            ['Bebidas',          'Gaseosas, aguas, jugos, cervezas y vinos'],
            ['Lácteos',          'Leches, yogures, quesos y cremas'],
            ['Panadería',        'Pan, facturas, galletitas y tostadas'],
            ['Fiambres',         'Fiambres y carnes frías en mostrador'],
            ['Almacén Seco',     'Pastas, arroz, legumbres, aceites y conservas'],
            ['Dietética / Granel','Harinas, azúcares, semillas y frutos secos al peso'],
            ['Limpieza',         'Artículos de limpieza del hogar'],
            ['Perfumería',       'Higiene personal y cosmética'],
        ];

        foreach ($catDefs as [$name, $desc]) {
            $cats[$name] = Category::create(['name' => $name, 'description' => $desc]);
        }

        // ─────────────────────────────────────────────────────────────
        // 2. Helper: código interno auto-incremental
        // ─────────────────────────────────────────────────────────────
        $seq = 1000;
        $next = function () use (&$seq): string {
            return (string) $seq++;
        };

        // ─────────────────────────────────────────────────────────────
        // 3. Productos
        // ─────────────────────────────────────────────────────────────

        // ── BEBIDAS (18) ─────────────────────────────────────────
        $b = $cats['Bebidas']->id;
        $bebidas = [
            ['Coca-Cola 375ml Lata',        '7790895000085',  380,  650,  200],
            ['Coca-Cola 1.5L',              '7791234567890',  800, 1200,   80],
            ['Coca-Cola 3L',                '7791234567907', 1400, 2100,   40],
            ['Pepsi 375ml Lata',            '7793600001023',  350,  590,  150],
            ['Pepsi 1.5L',                  '7793600001030',  750, 1100,   60],
            ['7 Up 1.5L',                   '7793600002001',  680, 1000,   55],
            ['Sprite 1.5L',                 '7791234568001',  680, 1000,   55],
            ['Agua Mineral 500ml',          '7790070000011',  200,  400,  300],
            ['Agua Mineral 1.5L',           '7790070000028',  320,  600,  150],
            ['Agua con Gas 1.5L',           '7790070000035',  380,  700,   90],
            ['Jugo Tang Naranja x 500ml',   '7790455001020',  250,  450,  100],
            ['Gatorade 500ml Naranja',      '7791234568101',  600,  950,   80],
            ['Cerveza Quilmes 1L',          '7790315001011', 1200, 1900,   60],
            ['Cerveza Heineken 473ml',      '8712000003067', 1000, 1600,   40],
            ['Vino Malbec Clos 750ml',      '7793710000014', 2000, 3200,   30],
            ['Vino Torrontés 750ml',        '7793710000021', 1800, 2800,   25],
            ['Leche Chocolatada La Serenísima 1L', '7790315002001', 900, 1400, 70],
            ['Energizante Monster 473ml',   '5099873046884', 1200, 1900,   50],
        ];
        foreach ($bebidas as [$name, $bar, $cost, $sell, $stock]) {
            Product::create(['name'=>$name,'barcode'=>$bar,'internal_code'=>$next(),
                'cost_price'=>$cost,'selling_price'=>$sell,'stock'=>$stock,
                'active'=>true,'is_sold_by_weight'=>false,'category_id'=>$b]);
        }

        // ── LÁCTEOS (10) ─────────────────────────────────────────
        $l = $cats['Lácteos']->id;
        $lacteos = [
            ['Leche Entera La Serenísima 1L',   '7790315000007',  700, 1050, 100],
            ['Leche Descremada La Serenísima 1L','7790315000014',  730, 1100,  80],
            ['Leche Entera Sancor 1L',           '7793312000001',  680, 1020,  90],
            ['Yogur Entero Vainilla 200g',        '7790315003001',  350,  550,  60],
            ['Yogur Bebible Frutilla 200ml',      '7790315003018',  300,  480,  70],
            ['Queso Cremoso Buffet x 100g',       '7790315004001',  600,  950,  40],
            ['Queso de Mano Rallado 200g',        '7791234568201',  700, 1100,  35],
            ['Crema de Leche La Serenísima 200ml','7790315005001',  600,  950,  50],
            ['Manteca La Serenísima 200g',        '7790315006001',  900, 1400,  45],
            ['Ricota Serenísima 250g',            '7790315007001',  500,  800,  30],
        ];
        foreach ($lacteos as [$name,$bar,$cost,$sell,$stock]) {
            Product::create(['name'=>$name,'barcode'=>$bar,'internal_code'=>$next(),
                'cost_price'=>$cost,'selling_price'=>$sell,'stock'=>$stock,
                'active'=>true,'is_sold_by_weight'=>false,'category_id'=>$l]);
        }

        // ── PANADERÍA (8) ────────────────────────────────────────
        $p = $cats['Panadería']->id;
        $panaderia = [
            ['Pan Lactal Bimbo Clásico 480g',     '7793810000001',  900, 1400,  50],
            ['Pan de Hamburguesas x6',             '7791234568301',  700, 1050,  40],
            ['Galletitas Oreo 312g',               '7622300011021',  800, 1250,  60],
            ['Galletitas Pepitos 100g',             '7793932000001',  300,  480,  80],
            ['Facturas Medialunas x6',             '0000000000001',  600,  950,  30],
            ['Tostadas Lactal x30',                '7791234568401',  700, 1100,  50],
            ['Roscas de Anís 250g',               '7791234568402',  550,  850,  35],
            ['Bizcochos Grases x12',              '7791234568403',  500,  800,  40],
        ];
        foreach ($panaderia as [$name,$bar,$cost,$sell,$stock]) {
            Product::create(['name'=>$name,'barcode'=>$bar,'internal_code'=>$next(),
                'cost_price'=>$cost,'selling_price'=>$sell,'stock'=>$stock,
                'active'=>true,'is_sold_by_weight'=>false,'category_id'=>$p]);
        }

        // ── FIAMBRES (8) ─────────────────────────────────────────
        $f = $cats['Fiambres']->id;
        $fiambres = [
            ['Jamón Cocido Premium x 100g',   '7791234568501', 1200, 1900, 20],
            ['Mortadela x 100g',              '7791234568502',  600,  950, 25],
            ['Salame Tandilero x 100g',       '7791234568503', 1500, 2400, 15],
            ['Queso Gouda x 100g',            '7791234568504', 1100, 1700, 18],
            ['Paleta x 100g',                 '7791234568505',  800, 1250, 22],
            ['Leberwurst x 100g',             '7791234568506',  700, 1100, 12],
            ['Salchichas de Viena x 6',       '7791234568507', 1000, 1600, 30],
            ['Chorizo para Asado x Kg',       '7791234568508', 2500, 3800, 10],
        ];
        foreach ($fiambres as [$name,$bar,$cost,$sell,$stock]) {
            Product::create(['name'=>$name,'barcode'=>$bar,'internal_code'=>$next(),
                'cost_price'=>$cost,'selling_price'=>$sell,'stock'=>$stock,
                'active'=>true,'is_sold_by_weight'=>false,'category_id'=>$f]);
        }

        // ── ALMACÉN SECO (20) ────────────────────────────────────
        $a = $cats['Almacén Seco']->id;
        $almacen = [
            ['Fideos Spaghetti Matarazzo 500g',   '7790070001001',  500,  780, 80],
            ['Fideos Moño Lucchetti 500g',         '7790070001002',  480,  750, 70],
            ['Arroz Parboil Tres Coronas 1Kg',    '7790623000001',  700, 1100, 60],
            ['Arroz Doble Carolina 1Kg',          '7790623000018',  650, 1000, 55],
            ['Lentejas La Cabaña 500g',           '7791234568601',  550,  850, 40],
            ['Garbanzos La Cabaña 500g',          '7791234568602',  600,  950, 35],
            ['Aceite Girasol Cocinero 1.5L',      '7790369000001', 1800, 2800, 50],
            ['Aceite de Oliva Conosur 500ml',     '7791234568603', 2500, 3900, 25],
            ['Azúcar Ledesma 1Kg',               '7791234568604',  700, 1100, 90],
            ['Sal Fina Celusal 1Kg',              '7791234568605',  400,  650, 80],
            ['Tomates Perita en Lata La Merced 400g','7791234568606',  450,  700, 60],
            ['Choclo en Lata Arcor 300g',         '7791234568607',  500,  780, 45],
            ['Atún al Natural La Fragata 170g',   '7791234568608',  800, 1250, 50],
            ['Mermelada Arcor Frutilla 390g',     '7791234568609',  600,  950, 40],
            ['Mayonesa Hellmann\'s 250g',          '7791234568610',  700, 1100, 55],
            ['Ketchup Heinz 397g',                '7791234568611',  750, 1150, 45],
            ['Cacao Amargo Chocolina 200g',       '7791234568612',  600,  950, 35],
            ['Café molido La Virginia 500g',      '7791234568613', 2500, 3800, 30],
            ['Yerba Mate Cruz de Malta 1Kg',      '7791234568614', 2000, 3100, 50],
            ['Puré de Tomate Mutti 500g',         '7791234568615',  700, 1100, 40],
        ];
        foreach ($almacen as [$name,$bar,$cost,$sell,$stock]) {
            Product::create(['name'=>$name,'barcode'=>$bar,'internal_code'=>$next(),
                'cost_price'=>$cost,'selling_price'=>$sell,'stock'=>$stock,
                'active'=>true,'is_sold_by_weight'=>false,'category_id'=>$a]);
        }

        // ── DIETÉTICA / GRANEL — por peso (14) ──────────────────
        $d = $cats['Dietética / Granel']->id;
        $granel = [
            ['Harina 0000 (Granel)',        450,  700, 80.0],
            ['Harina 000 Leudante (Granel)', 500,  780, 60.0],
            ['Harina de Maíz (Granel)',     550,  850, 50.0],
            ['Azúcar Mascabo (Granel)',      900, 1400, 30.0],
            ['Azúcar Impalpable (Granel)',   700, 1100, 40.0],
            ['Cacao en Polvo (Granel)',      900, 1400, 25.0],
            ['Arroz Integral (Granel)',      700, 1100, 45.0],
            ['Avena Arrollada (Granel)',     600,  950, 50.0],
            ['Maní Pelado (Granel)',        1100, 1700, 20.0],
            ['Almendras Enteras (Granel)',  3500, 5500, 10.0],
            ['Nueces Peladas (Granel)',     3000, 4800, 12.0],
            ['Semillas de Chía (Granel)',   900, 1400, 15.0],
            ['Semillas de Lino (Granel)',   700, 1100, 18.0],
            ['Coco Rallado (Granel)',       1200, 1900, 10.0],
        ];
        foreach ($granel as [$name,$cost,$sell,$stock]) {
            Product::create(['name'=>$name,'barcode'=>null,'internal_code'=>$next(),
                'cost_price'=>$cost,'selling_price'=>$sell,'stock'=>$stock,
                'active'=>true,'is_sold_by_weight'=>true,'category_id'=>$d]);
        }

        // ── LIMPIEZA (12) ────────────────────────────────────────
        $lim = $cats['Limpieza']->id;
        $limpieza = [
            ['Lavandina Ayudín 1L',            '7792226000001',  450,  700, 60],
            ['Detergente Magistral 500ml',      '7792226000018',  500,  780, 55],
            ['Jabón en Polvo Ala 1Kg',          '7792226000025', 1200, 1900, 40],
            ['Jabón en Polvo Skip 3Kg',         '7792226000032', 3000, 4700, 20],
            ['Suavizante Comfort 1L',           '7792226000049',  900, 1400, 35],
            ['Limpiapisos Pino Limpiador 1L',   '7792226000056',  600,  950, 50],
            ['Desengrasante Ayudín 500ml',      '7792226000063',  700, 1100, 40],
            ['Papel Higiénico Higienol x4',     '7792226000070',  900, 1400, 80],
            ['Toallas de Papel x2',             '7792226000087',  700, 1100, 60],
            ['Bolsas de Residuos x20',          '7792226000094',  600,  950, 70],
            ['Escobilla para Baño',             '7792226000101',  800, 1250, 25],
            ['Esponja Rejilla Pack x2',         '7792226000118',  350,  550, 80],
        ];
        foreach ($limpieza as [$name,$bar,$cost,$sell,$stock]) {
            Product::create(['name'=>$name,'barcode'=>$bar,'internal_code'=>$next(),
                'cost_price'=>$cost,'selling_price'=>$sell,'stock'=>$stock,
                'active'=>true,'is_sold_by_weight'=>false,'category_id'=>$lim]);
        }

        // ── PERFUMERÍA (10) ──────────────────────────────────────
        $per = $cats['Perfumería']->id;
        $perfumeria = [
            ['Shampoo Head & Shoulders 375ml',  '4015600542948', 1500, 2350, 30],
            ['Acondicionador Pantene 350ml',    '7501007401101', 1400, 2200, 28],
            ['Jabón Dove Blanco x3',            '8714100792797',  900, 1400, 50],
            ['Desodorante Rexona 150ml',        '7791058001001', 1200, 1900, 40],
            ['Colonia Baby Classic 100ml',      '7791234568701',  800, 1250, 25],
            ['Cepillo Dental Oral-B x2',        '3014260073497',  800, 1250, 35],
            ['Pasta Dental Colgate Triple 90g', '7509546655048',  700, 1100, 45],
            ['Afeitadora BIC 2 Hojas x5',       '3086123008059', 1200, 1900, 20],
            ['Crema Corporal Nivea 400ml',      '4005900004048', 2000, 3100, 20],
            ['Algodón Farmacéutico 100g',       '7791234568702',  400,  650, 40],
        ];
        foreach ($perfumeria as [$name,$bar,$cost,$sell,$stock]) {
            Product::create(['name'=>$name,'barcode'=>$bar,'internal_code'=>$next(),
                'cost_price'=>$cost,'selling_price'=>$sell,'stock'=>$stock,
                'active'=>true,'is_sold_by_weight'=>false,'category_id'=>$per]);
        }

        $this->command->info('✅ BigCatalogSeeder: ' . Product::count() . ' productos creados en ' . Category::count() . ' categorías.');
    }
}
