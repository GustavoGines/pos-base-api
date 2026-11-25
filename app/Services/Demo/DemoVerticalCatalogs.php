<?php

namespace App\Services\Demo;

/**
 * Catálogos curados y realistas por vertical comercial para el Modo Exploración Demo.
 * Cada vertical retorna categorías, marcas, proveedores, clientes y ~26-28 productos
 * diseñados para lucir las capacidades nativas del rubro en Caja4U.
 */
class DemoVerticalCatalogs
{
    /**
     * @return array{
     *   categories: array<int, array{key: string, name: string, description: string}>,
     *   brands: array<int, string>,
     *   suppliers: array<int, array<string, mixed>>,
     *   customers: array<int, array<string, mixed>>,
     *   products: array<int, array<string, mixed>>,
     *   combo: array{name: string, internal_code: string, selling_price: float, category_key: string, brand: string, items: array<int, array{code: string, qty: float}>},
     *   offers: array<int, array{name: string, description: string, discount_percentage: float, product_codes: array<int, string>}>,
     *   quotes: array<int, array<string, mixed>>,
     *   checks: array<int, array<string, mixed>>,
     *   delivery_notes: bool
     * }
     */
    public static function forVertical(string $vertical): array
    {
        $normalized = strtolower(trim($vertical));

        $catalog = match ($normalized) {
            'butcher' => self::butcherCatalog(),
            'hardware_store' => self::hardwareStoreCatalog(),
            'apparel' => self::apparelCatalog(),
            'tech' => self::techCatalog(),
            'general' => self::generalCatalog(),
            default => self::retailCatalog(),
        };

        $catalog['suppliers'] = self::commonSuppliers($normalized);
        $catalog['customers'] = self::commonCustomers($normalized);

        return $catalog;
    }

    /**
     * Mini-catálogo adicional cuando la licencia Polirrubro incluye un rubro secundario.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function secondarySnippetFor(string $vertical): array
    {
        return match (strtolower(trim($vertical))) {
            'butcher' => [
                ['code' => 'SEC-B01', 'name' => 'Asado de Tira Especial (x Kg)', 'cost' => 6900, 'price' => 9800, 'stock' => 28, 'min_stock' => 6, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Novillo', 'loc' => 'Cámara 1', 'iva' => 10.50],
                ['code' => 'SEC-B02', 'name' => 'Milanesa de Nalga Preparada (x Kg)', 'cost' => 7400, 'price' => 10600, 'stock' => 18, 'min_stock' => 5, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Rebozada', 'loc' => 'Batea 1', 'iva' => 10.50],
                ['code' => 'SEC-B03', 'name' => 'Chorizo Parrillero Puro Cerdo (x Kg)', 'cost' => 4500, 'price' => 6800, 'stock' => 14, 'min_stock' => 4, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Fresco', 'loc' => 'Batea 2', 'iva' => 10.50],
                ['code' => 'SEC-B04', 'name' => 'Pechuga de Pollo Sin Hueso (x Kg)', 'cost' => 5100, 'price' => 7500, 'stock' => 22, 'min_stock' => 5, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Fresco', 'loc' => 'Cámara 2', 'iva' => 10.50],
            ],
            'hardware_store' => [
                ['code' => 'SEC-H01', 'name' => 'Cemento Loma Negra 50Kg', 'cost' => 7800, 'price' => 10900, 'stock' => 60, 'min_stock' => 12, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Bolsa 50Kg', 'loc' => 'Galpón 1', 'iva' => 21.00],
                ['code' => 'SEC-H02', 'name' => 'Cinta Métrica Acero 5m Reforzada', 'cost' => 3400, 'price' => 5600, 'stock' => 18, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => '5m x 19mm', 'loc' => 'Tablero A', 'iva' => 21.00],
                ['code' => 'SEC-H03', 'name' => 'Cable Unipolar 2.5mm IRAM (x Metro)', 'cost' => 620, 'price' => 980, 'stock' => 150, 'min_stock' => 25, 'unit_type' => 'mt', 'weight' => true, 'spec' => '2.5mm Azul', 'loc' => 'Estante C1', 'iva' => 21.00],
                ['code' => 'SEC-H04', 'name' => 'Adhesivo Sellador Silicona Transparente', 'cost' => 2900, 'price' => 4650, 'stock' => 24, 'min_stock' => 5, 'unit_type' => 'un', 'weight' => false, 'spec' => '280ml', 'loc' => 'Pasillo 2', 'iva' => 21.00],
            ],
            'apparel' => [
                ['code' => 'SEC-A01', 'name' => 'Remera Básica Algodón Peinado Negra (L)', 'cost' => 7200, 'price' => 12900, 'stock' => 18, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Talle L · Negro', 'loc' => 'Perchero A', 'iva' => 21.00],
                ['code' => 'SEC-A02', 'name' => 'Buzo Canguro Friza Gris Melange (M)', 'cost' => 16500, 'price' => 27900, 'stock' => 10, 'min_stock' => 3, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Talle M · Gris', 'loc' => 'Perchero B', 'iva' => 21.00],
                ['code' => 'SEC-A03', 'name' => 'Medias Soquete Algodón Pack x3', 'cost' => 2100, 'price' => 3900, 'stock' => 32, 'min_stock' => 6, 'unit_type' => 'par', 'weight' => false, 'spec' => 'Único 39-44', 'loc' => 'Cajón 2', 'iva' => 21.00],
                ['code' => 'SEC-A04', 'name' => 'Gorra Gabardina Visera Curva', 'cost' => 4100, 'price' => 7500, 'stock' => 14, 'min_stock' => 3, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Ajustable', 'loc' => 'Exhibidor 1', 'iva' => 21.00],
            ],
            'tech' => [
                ['code' => 'SEC-T01', 'name' => 'Cargador Rápido USB-C 25W Original', 'cost' => 9200, 'price' => 15900, 'stock' => 20, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => '25W PD', 'loc' => 'Vitrina 1', 'iva' => 21.00],
                ['code' => 'SEC-T02', 'name' => 'Cable USB-C a USB-C Mallado 1.5m', 'cost' => 3200, 'price' => 5900, 'stock' => 28, 'min_stock' => 6, 'unit_type' => 'un', 'weight' => false, 'spec' => '1.5m 60W', 'loc' => 'Panel Acc.', 'iva' => 21.00],
                ['code' => 'SEC-T03', 'name' => 'Vidrio Templado 9D Full Glue Universal', 'cost' => 1400, 'price' => 3800, 'stock' => 45, 'min_stock' => 10, 'unit_type' => 'un', 'weight' => false, 'spec' => '9D', 'loc' => 'Gaveta 3', 'iva' => 21.00],
                ['code' => 'SEC-T04', 'name' => 'Auriculares Inalámbricos TWS Bluetooth 5.3', 'cost' => 11500, 'price' => 19900, 'stock' => 12, 'min_stock' => 3, 'unit_type' => 'un', 'weight' => false, 'spec' => 'BT 5.3', 'loc' => 'Vitrina 2', 'iva' => 21.00],
            ],
            'general' => [
                ['code' => 'SEC-G01', 'name' => 'Resma Papel A4 Autor 75gr x500 Hojas', 'cost' => 4900, 'price' => 7600, 'stock' => 35, 'min_stock' => 8, 'unit_type' => 'un', 'weight' => false, 'spec' => 'A4 · 75g', 'loc' => 'Estante 1', 'iva' => 21.00],
                ['code' => 'SEC-G02', 'name' => 'Termo Acero Inoxidable 1L Doble Pared', 'cost' => 14800, 'price' => 24500, 'stock' => 12, 'min_stock' => 3, 'unit_type' => 'un', 'weight' => false, 'spec' => '1 Litro', 'loc' => 'Góndola Bazar', 'iva' => 21.00],
                ['code' => 'SEC-G03', 'name' => 'Cuaderno Tapa Dura A4 Rayado 100 Hojas', 'cost' => 3400, 'price' => 5800, 'stock' => 24, 'min_stock' => 5, 'unit_type' => 'un', 'weight' => false, 'spec' => 'A4 Rayado', 'loc' => 'Estante 2', 'iva' => 21.00],
                ['code' => 'SEC-G04', 'name' => 'Set Marcadores Pastel x6 Unidades', 'cost' => 2300, 'price' => 4200, 'stock' => 20, 'min_stock' => 5, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Pack x6', 'loc' => 'Mostrador', 'iva' => 21.00],
            ],
            default => [
                ['code' => 'SEC-R01', 'name' => 'Gaseosa Coca-Cola Sabor Original 2.25L', 'cost' => 2350, 'price' => 3450, 'stock' => 36, 'min_stock' => 8, 'unit_type' => 'un', 'weight' => false, 'spec' => '2.25L', 'loc' => 'Heladera 1', 'iva' => 21.00],
                ['code' => 'SEC-R02', 'name' => 'Yerba Mate Playadito Suave 1Kg', 'cost' => 3400, 'price' => 4950, 'stock' => 24, 'min_stock' => 6, 'unit_type' => 'un', 'weight' => false, 'spec' => '1 Kg', 'loc' => 'Góndola 2', 'iva' => 21.00],
                ['code' => 'SEC-R03', 'name' => 'Agua Mineral Villavicencio Sin Gas 2L', 'cost' => 980, 'price' => 1550, 'stock' => 42, 'min_stock' => 10, 'unit_type' => 'un', 'weight' => false, 'spec' => '2 Litros', 'loc' => 'Heladera 2', 'iva' => 21.00],
                ['code' => 'SEC-R04', 'name' => 'Galletitas Surtido Bagley 400g', 'cost' => 1450, 'price' => 2250, 'stock' => 25, 'min_stock' => 6, 'unit_type' => 'un', 'weight' => false, 'spec' => '400g', 'loc' => 'Góndola 3', 'iva' => 21.00],
            ],
        };
    }

    private static function retailCatalog(): array
    {
        return [
            'categories' => [
                ['key' => 'bebidas', 'name' => 'Bebidas y Gaseosas', 'description' => 'Gaseosas, aguas, cervezas y jugos'],
                ['key' => 'almacen', 'name' => 'Almacén y Despensa', 'description' => 'Yerba, aceites, fideos, galletitas y secos'],
                ['key' => 'lacteos', 'name' => 'Lácteos y Frescos', 'description' => 'Leches, yogures, quesos y mantecas con lote FEFO'],
                ['key' => 'fiambreria', 'name' => 'Fiambrería al Peso', 'description' => 'Quesos y fiambres fraccionados por balanza'],
                ['key' => 'limpieza', 'name' => 'Limpieza y Hogar', 'description' => 'Detergentes, lavandina, papel higiénico'],
            ],
            'brands' => ['Coca-Cola', 'La Serenísima', 'Marolio', 'Paladini', 'Ala'],
            'products' => [
                ['code' => 'RET101', 'name' => 'Gaseosa Coca-Cola Sabor Original 2.25L', 'cat' => 'bebidas', 'brand' => 'Coca-Cola', 'cost' => 2350, 'price' => 3450, 'stock' => 48, 'min_stock' => 10, 'unit_type' => 'un', 'weight' => false, 'spec' => '2.25 L', 'loc' => 'Heladera 1', 'iva' => 21.00],
                ['code' => 'RET102', 'name' => 'Gaseosa Sprite Lima Limón 1.5L', 'cat' => 'bebidas', 'brand' => 'Coca-Cola', 'cost' => 1850, 'price' => 2750, 'stock' => 32, 'min_stock' => 8, 'unit_type' => 'un', 'weight' => false, 'spec' => '1.5 L', 'loc' => 'Heladera 1', 'iva' => 21.00],
                ['code' => 'RET103', 'name' => 'Agua Mineral Sin Gas 2L', 'cat' => 'bebidas', 'brand' => 'Coca-Cola', 'cost' => 920, 'price' => 1490, 'stock' => 54, 'min_stock' => 12, 'unit_type' => 'un', 'weight' => false, 'spec' => '2.0 L', 'loc' => 'Góndola 1', 'iva' => 21.00],
                ['code' => 'RET104', 'name' => 'Cerveza Rubia Lata 473ml', 'cat' => 'bebidas', 'brand' => 'Coca-Cola', 'cost' => 1350, 'price' => 2100, 'stock' => 60, 'min_stock' => 12, 'unit_type' => 'un', 'weight' => false, 'spec' => '473 ml', 'loc' => 'Heladera 2', 'iva' => 21.00],
                ['code' => 'RET105', 'name' => 'Jugo en Polvo Naranja Pack x10', 'cat' => 'bebidas', 'brand' => 'Marolio', 'cost' => 1900, 'price' => 2950, 'stock' => 22, 'min_stock' => 5, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Pack x10', 'loc' => 'Góndola 1', 'iva' => 21.00],
                ['code' => 'RET201', 'name' => 'Yerba Mate Tradicional 1Kg', 'cat' => 'almacen', 'brand' => 'Marolio', 'cost' => 3150, 'price' => 4690, 'stock' => 34, 'min_stock' => 8, 'unit_type' => 'un', 'weight' => false, 'spec' => '1 Kg', 'loc' => 'Góndola 2', 'iva' => 21.00],
                ['code' => 'RET202', 'name' => 'Aceite Girasol 1.5L', 'cat' => 'almacen', 'brand' => 'Marolio', 'cost' => 2550, 'price' => 3790, 'stock' => 28, 'min_stock' => 6, 'unit_type' => 'un', 'weight' => false, 'spec' => '1.5 L', 'loc' => 'Góndola 2', 'iva' => 21.00],
                ['code' => 'RET203', 'name' => 'Fideos Tallarines 500g', 'cat' => 'almacen', 'brand' => 'Marolio', 'cost' => 820, 'price' => 1350, 'stock' => 45, 'min_stock' => 10, 'unit_type' => 'un', 'weight' => false, 'spec' => '500 g', 'loc' => 'Góndola 2', 'iva' => 21.00],
                ['code' => 'RET204', 'name' => 'Arroz Largo Fino 1Kg', 'cat' => 'almacen', 'brand' => 'Marolio', 'cost' => 1350, 'price' => 2150, 'stock' => 4, 'min_stock' => 10, 'unit_type' => 'un', 'weight' => false, 'spec' => '1 Kg', 'loc' => 'Góndola 2', 'iva' => 21.00],
                ['code' => 'RET205', 'name' => 'Azúcar Común Tipo A 1Kg', 'cat' => 'almacen', 'brand' => 'Marolio', 'cost' => 980, 'price' => 1550, 'stock' => 38, 'min_stock' => 8, 'unit_type' => 'un', 'weight' => false, 'spec' => '1 Kg', 'loc' => 'Góndola 2', 'iva' => 21.00],
                ['code' => 'RET206', 'name' => 'Harina 0000 Refinada 1Kg', 'cat' => 'almacen', 'brand' => 'Marolio', 'cost' => 760, 'price' => 1250, 'stock' => 30, 'min_stock' => 6, 'unit_type' => 'un', 'weight' => false, 'spec' => '1 Kg', 'loc' => 'Góndola 2', 'iva' => 10.50],
                ['code' => 'RET207', 'name' => 'Galletitas Dulces Surtidas 400g', 'cat' => 'almacen', 'brand' => 'Marolio', 'cost' => 1420, 'price' => 2250, 'stock' => 25, 'min_stock' => 5, 'unit_type' => 'un', 'weight' => false, 'spec' => '400 g', 'loc' => 'Góndola 3', 'iva' => 21.00],
                ['code' => 'RET208', 'name' => 'Café Molido Clásico 500g', 'cat' => 'almacen', 'brand' => 'Marolio', 'cost' => 4800, 'price' => 7200, 'stock' => 3, 'min_stock' => 6, 'unit_type' => 'un', 'weight' => false, 'spec' => '500 g', 'loc' => 'Góndola 3', 'iva' => 21.00],
                ['code' => 'RET301', 'name' => 'Leche Entera Larga Vida 1L', 'cat' => 'lacteos', 'brand' => 'La Serenísima', 'cost' => 1280, 'price' => 1890, 'stock' => 40, 'min_stock' => 12, 'unit_type' => 'un', 'weight' => false, 'spec' => '1 L', 'loc' => 'Heladera Lácteos', 'iva' => 21.00, 'track_batches' => true, 'exp_days' => 25],
                ['code' => 'RET302', 'name' => 'Yogur Bebible Frutilla 1L', 'cat' => 'lacteos', 'brand' => 'La Serenísima', 'cost' => 1650, 'price' => 2490, 'stock' => 16, 'min_stock' => 6, 'unit_type' => 'un', 'weight' => false, 'spec' => '1 L', 'loc' => 'Heladera Lácteos', 'iva' => 21.00, 'track_batches' => true, 'exp_days' => 4, 'batch_mode' => 'near_expiry'],
                ['code' => 'RET303', 'name' => 'Manteca Clásica 200g', 'cat' => 'lacteos', 'brand' => 'La Serenísima', 'cost' => 1950, 'price' => 2890, 'stock' => 18, 'min_stock' => 5, 'unit_type' => 'un', 'weight' => false, 'spec' => '200 g', 'loc' => 'Heladera Lácteos', 'iva' => 21.00, 'track_batches' => true, 'exp_days' => 18],
                ['code' => 'RET304', 'name' => 'Queso Cremoso Horma Fraccionada (x Kg)', 'cat' => 'lacteos', 'brand' => 'La Serenísima', 'cost' => 6200, 'price' => 9200, 'stock' => 12.5, 'min_stock' => 3, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Fresco', 'loc' => 'Heladera Lácteos', 'iva' => 21.00, 'track_batches' => true, 'exp_days' => 12],
                ['code' => 'RET305', 'name' => 'Postre Lácteo Vainilla x2 (Lote Control)', 'cat' => 'lacteos', 'brand' => 'La Serenísima', 'cost' => 1100, 'price' => 1750, 'stock' => 8, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => '2 x 120g', 'loc' => 'Heladera Lácteos', 'iva' => 21.00, 'track_batches' => true, 'exp_days' => -2, 'batch_mode' => 'with_expired'],
                ['code' => 'RET401', 'name' => 'Jamón Cocido Natural (x Kg)', 'cat' => 'fiambreria', 'brand' => 'Paladini', 'cost' => 7800, 'price' => 11900, 'stock' => 8.4, 'min_stock' => 2, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Feteado', 'loc' => 'Fiambrera', 'iva' => 21.00],
                ['code' => 'RET402', 'name' => 'Queso Barra Tybo (x Kg)', 'cat' => 'fiambreria', 'brand' => 'Paladini', 'cost' => 7100, 'price' => 10800, 'stock' => 9.2, 'min_stock' => 2, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Feteado', 'loc' => 'Fiambrera', 'iva' => 21.00],
                ['code' => 'RET403', 'name' => 'Salame Tipo Milán (x Kg)', 'cat' => 'fiambreria', 'brand' => 'Paladini', 'cost' => 9400, 'price' => 14200, 'stock' => 5.5, 'min_stock' => 1.5, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Estacionado', 'loc' => 'Fiambrera', 'iva' => 21.00],
                ['code' => 'RET404', 'name' => 'Pan Lactal Blanco Grande 600g', 'cat' => 'almacen', 'brand' => 'Paladini', 'cost' => 1950, 'price' => 2990, 'stock' => 15, 'min_stock' => 5, 'unit_type' => 'un', 'weight' => false, 'spec' => '600 g', 'loc' => 'Góndola 4', 'iva' => 21.00],
                ['code' => 'RET501', 'name' => 'Jabón Líquido para Ropa 3L', 'cat' => 'limpieza', 'brand' => 'Ala', 'cost' => 4900, 'price' => 7450, 'stock' => 14, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => '3.0 L', 'loc' => 'Góndola Limpieza', 'iva' => 21.00],
                ['code' => 'RET502', 'name' => 'Lavandina Concentrada 2L', 'cat' => 'limpieza', 'brand' => 'Ala', 'cost' => 1300, 'price' => 2150, 'stock' => 2, 'min_stock' => 8, 'unit_type' => 'un', 'weight' => false, 'spec' => '2.0 L', 'loc' => 'Góndola Limpieza', 'iva' => 21.00],
                ['code' => 'RET503', 'name' => 'Detergente Limón 750ml', 'cat' => 'limpieza', 'brand' => 'Ala', 'cost' => 1450, 'price' => 2350, 'stock' => 22, 'min_stock' => 6, 'unit_type' => 'un', 'weight' => false, 'spec' => '750 ml', 'loc' => 'Góndola Limpieza', 'iva' => 21.00],
                ['code' => 'RET504', 'name' => 'Papel Higiénico Doble Hoja x4', 'cat' => 'limpieza', 'brand' => 'Ala', 'cost' => 1750, 'price' => 2850, 'stock' => 26, 'min_stock' => 6, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Pack x4', 'loc' => 'Góndola Limpieza', 'iva' => 21.00],
            ],
            'combo' => [
                'name' => 'Promo Picada Clásica (Coca 2.25L + Salame + Queso)',
                'internal_code' => 'RET-CMB01',
                'selling_price' => 8900.00,
                'category_key' => 'fiambreria',
                'brand' => 'Paladini',
                'items' => [
                    ['code' => 'RET101', 'qty' => 1],
                    ['code' => 'RET403', 'qty' => 0.25],
                    ['code' => 'RET402', 'qty' => 0.25],
                ],
            ],
            'offers' => [
                ['name' => 'Semana de Bebidas (-12%)', 'description' => 'Descuento directo en gaseosas seleccionadas', 'discount_percentage' => 12.00, 'product_codes' => ['RET101', 'RET102']],
                ['name' => 'Despensa Familiar (-10%)', 'description' => 'Promo en yerba y aceites', 'discount_percentage' => 10.00, 'product_codes' => ['RET201', 'RET202']],
            ],
            'quotes' => [
                ['status' => 'pending', 'customer_name' => 'Comedor Barrio Norte', 'customer_phone' => '3704-512233', 'notes' => 'Pedido semanal de bebidas y lácteos.', 'price_list' => 'base', 'days_offset' => 10, 'items' => [['code' => 'RET101', 'qty' => 12], ['code' => 'RET301', 'qty' => 20]]],
                ['status' => 'approved', 'customer_name' => 'Buffet Club Deportivo', 'customer_phone' => '3704-441100', 'notes' => 'Aprobado para retiro viernes por la tarde.', 'price_list' => 'mayorista', 'days_offset' => 7, 'items' => [['code' => 'RET104', 'qty' => 24], ['code' => 'RET404', 'qty' => 10]]],
                ['status' => 'expired', 'customer_name' => 'Kiosco La Esquina', 'customer_phone' => '3704-498877', 'notes' => 'Cotización vencida sin confirmación.', 'price_list' => 'base', 'days_offset' => -3, 'items' => [['code' => 'RET201', 'qty' => 6]]],
            ],
            'checks' => [
                ['bank' => 'Banco Galicia', 'number' => '44892104', 'amount' => 145000.00, 'days_due' => 7, 'issuer' => 'Supermercado Los Primos SRL', 'cuit' => '30712345671', 'status' => 'in_wallet'],
                ['bank' => 'Banco Nación', 'number' => '88231940', 'amount' => 92000.00, 'days_due' => 14, 'issuer' => 'Kiosco El Paso', 'cuit' => '20321148906', 'status' => 'in_wallet'],
                ['bank' => 'Banco Santander', 'number' => '77123901', 'amount' => 180000.00, 'days_due' => 10, 'issuer' => 'Construcciones Norte SA', 'cuit' => '30659821431', 'status' => 'endorsed'],
            ],
            'delivery_notes' => false,
        ];
    }

    private static function butcherCatalog(): array
    {
        return [
            'categories' => [
                ['key' => 'vacuno', 'name' => 'Cortes Vacunos', 'description' => 'Asado, vacío, nalga, lomo y molida especial'],
                ['key' => 'cerdo', 'name' => 'Cortes de Cerdo', 'description' => 'Bondiola, pechito, costeleta y chorizos'],
                ['key' => 'pollo', 'name' => 'Pollería y Granja', 'description' => 'Pollo entero, suprema, pata muslo y huevos'],
                ['key' => 'elaborados', 'name' => 'Milanesas y Elaborados', 'description' => 'Preparados diarios de mostrador'],
                ['key' => 'anexos', 'name' => 'Carbón, Condimentos y Anexos', 'description' => 'Carbón vegetal, chimichurri y sal parrillera'],
            ],
            'brands' => ['Frigorífico Guaraní', 'Granja Tres Arroyos', 'Campo Austral', 'Don Tito', 'Dos Anclas'],
            'products' => [
                ['code' => 'CAR101', 'name' => 'Asado de Tira Especial (x Kg)', 'cat' => 'vacuno', 'brand' => 'Frigorífico Guaraní', 'cost' => 6800, 'price' => 9800, 'stock' => 38.5, 'min_stock' => 8, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Novillo Especial', 'loc' => 'Cámara 1', 'iva' => 10.50, 'track_batches' => true, 'exp_days' => 5],
                ['code' => 'CAR102', 'name' => 'Vacío Fino de Novillo (x Kg)', 'cat' => 'vacuno', 'brand' => 'Frigorífico Guaraní', 'cost' => 7600, 'price' => 10900, 'stock' => 26.0, 'min_stock' => 6, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Novillo Especial', 'loc' => 'Cámara 1', 'iva' => 10.50, 'track_batches' => true, 'exp_days' => 6],
                ['code' => 'CAR103', 'name' => 'Nalga / Bola de Lomo p/ Milanesa (x Kg)', 'cat' => 'vacuno', 'brand' => 'Frigorífico Guaraní', 'cost' => 7900, 'price' => 11200, 'stock' => 22.0, 'min_stock' => 5, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Sin Grasa', 'loc' => 'Batea 1', 'iva' => 10.50, 'track_batches' => true, 'exp_days' => 4, 'batch_mode' => 'near_expiry'],
                ['code' => 'CAR104', 'name' => 'Carne Picada Especial (x Kg)', 'cat' => 'vacuno', 'brand' => 'Frigorífico Guaraní', 'cost' => 5600, 'price' => 8400, 'stock' => 14.5, 'min_stock' => 5, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Magra del Día', 'loc' => 'Batea 1', 'iva' => 10.50, 'track_batches' => true, 'exp_days' => 2],
                ['code' => 'CAR105', 'name' => 'Costeleta / Bife Ancho (x Kg)', 'cat' => 'vacuno', 'brand' => 'Frigorífico Guaraní', 'cost' => 6700, 'price' => 9600, 'stock' => 19.0, 'min_stock' => 4, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Novillo', 'loc' => 'Batea 1', 'iva' => 10.50],
                ['code' => 'CAR106', 'name' => 'Matambre de Ternera (x Kg)', 'cat' => 'vacuno', 'brand' => 'Frigorífico Guaraní', 'cost' => 7200, 'price' => 10400, 'stock' => 2.5, 'min_stock' => 5, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Tiernizado', 'loc' => 'Cámara 1', 'iva' => 10.50],
                ['code' => 'CAR107', 'name' => 'Osobuco / Puchero (x Kg)', 'cat' => 'vacuno', 'brand' => 'Frigorífico Guaraní', 'cost' => 4100, 'price' => 6200, 'stock' => 24.0, 'min_stock' => 5, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Fresco', 'loc' => 'Cámara 1', 'iva' => 10.50],
                ['code' => 'CAR201', 'name' => 'Bondiola de Cerdo Deshuesada (x Kg)', 'cat' => 'cerdo', 'brand' => 'Campo Austral', 'cost' => 5900, 'price' => 8600, 'stock' => 20.0, 'min_stock' => 5, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Cerdo Magro', 'loc' => 'Cámara 2', 'iva' => 10.50, 'track_batches' => true, 'exp_days' => 6],
                ['code' => 'CAR202', 'name' => 'Pechito de Cerdo con Manta (x Kg)', 'cat' => 'cerdo', 'brand' => 'Campo Austral', 'cost' => 5200, 'price' => 7700, 'stock' => 18.0, 'min_stock' => 4, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Cerdo Fresco', 'loc' => 'Batea 2', 'iva' => 10.50],
                ['code' => 'CAR203', 'name' => 'Chorizo Parrillero Puro Cerdo (x Kg)', 'cat' => 'cerdo', 'brand' => 'Don Tito', 'cost' => 4600, 'price' => 6900, 'stock' => 16.0, 'min_stock' => 4, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Atado Artesanal', 'loc' => 'Batea 2', 'iva' => 10.50],
                ['code' => 'CAR204', 'name' => 'Morcilla Bombón / Criolla (x Kg)', 'cat' => 'cerdo', 'brand' => 'Don Tito', 'cost' => 3800, 'price' => 5800, 'stock' => 10.0, 'min_stock' => 3, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Fresco', 'loc' => 'Batea 2', 'iva' => 10.50],
                ['code' => 'CAR301', 'name' => 'Pollo Entero Fresco (x Kg)', 'cat' => 'pollo', 'brand' => 'Granja Tres Arroyos', 'cost' => 2550, 'price' => 3690, 'stock' => 45.0, 'min_stock' => 10, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Granja', 'loc' => 'Cámara Pollo', 'iva' => 10.50, 'track_batches' => true, 'exp_days' => 4],
                ['code' => 'CAR302', 'name' => 'Suprema / Pechuga Sin Hueso (x Kg)', 'cat' => 'pollo', 'brand' => 'Granja Tres Arroyos', 'cost' => 4950, 'price' => 7200, 'stock' => 25.0, 'min_stock' => 6, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Sin Piel', 'loc' => 'Batea Pollo', 'iva' => 10.50],
                ['code' => 'CAR303', 'name' => 'Pata Muslo de Pollo (x Kg)', 'cat' => 'pollo', 'brand' => 'Granja Tres Arroyos', 'cost' => 2400, 'price' => 3550, 'stock' => 32.0, 'min_stock' => 8, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Fresco', 'loc' => 'Batea Pollo', 'iva' => 10.50],
                ['code' => 'CAR304', 'name' => 'Maple de Huevos Blancos x30u', 'cat' => 'pollo', 'brand' => 'Granja Tres Arroyos', 'cost' => 3800, 'price' => 5500, 'stock' => 3, 'min_stock' => 8, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Maple x30', 'loc' => 'Mostrador', 'iva' => 21.00],
                ['code' => 'CAR401', 'name' => 'Milanesa de Carne Rebozada (x Kg)', 'cat' => 'elaborados', 'brand' => 'Don Tito', 'cost' => 6900, 'price' => 9900, 'stock' => 14.0, 'min_stock' => 4, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Preparada Día', 'loc' => 'Batea Elaborados', 'iva' => 10.50, 'track_batches' => true, 'exp_days' => -1, 'batch_mode' => 'with_expired'],
                ['code' => 'CAR402', 'name' => 'Milanesa de Pollo Rebozada (x Kg)', 'cat' => 'elaborados', 'brand' => 'Don Tito', 'cost' => 4800, 'price' => 7100, 'stock' => 18.0, 'min_stock' => 4, 'unit_type' => 'kg', 'weight' => true, 'spec' => 'Preparada Día', 'loc' => 'Batea Elaborados', 'iva' => 10.50],
                ['code' => 'CAR403', 'name' => 'Hamburguesa Casera Pack x4u', 'cat' => 'elaborados', 'brand' => 'Don Tito', 'cost' => 3600, 'price' => 5400, 'stock' => 15, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Pack 480g', 'loc' => 'Freezer 1', 'iva' => 10.50],
                ['code' => 'CAR501', 'name' => 'Bolsa Carbón Vegetal Quebracho 5Kg', 'cat' => 'anexos', 'brand' => 'Don Tito', 'cost' => 2100, 'price' => 3400, 'stock' => 3, 'min_stock' => 10, 'unit_type' => 'bolsa', 'weight' => false, 'spec' => '5 Kg', 'loc' => 'Depósito Parrilla', 'iva' => 21.00],
                ['code' => 'CAR502', 'name' => 'Chimichurri Artesanal Frasco 250g', 'cat' => 'anexos', 'brand' => 'Dos Anclas', 'cost' => 1450, 'price' => 2400, 'stock' => 20, 'min_stock' => 5, 'unit_type' => 'un', 'weight' => false, 'spec' => '250 g', 'loc' => 'Estante Cond.', 'iva' => 21.00],
                ['code' => 'CAR503', 'name' => 'Sal Entrefina Parrillera 1Kg', 'cat' => 'anexos', 'brand' => 'Dos Anclas', 'cost' => 1100, 'price' => 1850, 'stock' => 25, 'min_stock' => 5, 'unit_type' => 'un', 'weight' => false, 'spec' => '1 Kg', 'loc' => 'Estante Cond.', 'iva' => 21.00],
                ['code' => 'CAR504', 'name' => 'Provoleta Estacionada Feteada 250g', 'cat' => 'anexos', 'brand' => 'Don Tito', 'cost' => 2600, 'price' => 4100, 'stock' => 14, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => '250 g', 'loc' => 'Heladera', 'iva' => 21.00],
            ],
            'combo' => [
                'name' => 'Bolsón Parrillero Familiar (Asado 2Kg + Vacío 1Kg + Chorizo 1Kg + Carbón)',
                'internal_code' => 'CAR-CMB01',
                'selling_price' => 37500.00,
                'category_key' => 'vacuno',
                'brand' => 'Frigorífico Guaraní',
                'items' => [
                    ['code' => 'CAR101', 'qty' => 2],
                    ['code' => 'CAR102', 'qty' => 1],
                    ['code' => 'CAR203', 'qty' => 1],
                    ['code' => 'CAR501', 'qty' => 1],
                ],
            ],
            'offers' => [
                ['name' => 'Miércoles de Milanesas (-10%)', 'description' => 'Descuento especial en milanesas preparadas', 'discount_percentage' => 10.00, 'product_codes' => ['CAR401', 'CAR402']],
                ['name' => 'Promo Granja (-8%)', 'description' => 'Pollo entero y pata muslo fresco', 'discount_percentage' => 8.00, 'product_codes' => ['CAR301', 'CAR303']],
            ],
            'quotes' => [
                ['status' => 'pending', 'customer_name' => 'Parrilla El Fogón', 'customer_phone' => '3704-552211', 'notes' => 'Abastecimiento semanal cortes de tira y vacío.', 'price_list' => 'mayorista', 'days_offset' => 5, 'items' => [['code' => 'CAR101', 'qty' => 15], ['code' => 'CAR102', 'qty' => 10]]],
                ['status' => 'approved', 'customer_name' => 'Evento Fin de Año Club', 'customer_phone' => '3704-419988', 'notes' => 'Entrega sábado 09:00 hs en cámara fría.', 'price_list' => 'mayorista', 'days_offset' => 3, 'items' => [['code' => 'CAR101', 'qty' => 25], ['code' => 'CAR203', 'qty' => 10]]],
                ['status' => 'expired', 'customer_name' => 'Rotisería Central', 'customer_phone' => '3704-467711', 'notes' => 'Cotización semanal vencida.', 'price_list' => 'base', 'days_offset' => -2, 'items' => [['code' => 'CAR302', 'qty' => 12]]],
            ],
            'checks' => [
                ['bank' => 'Banco Galicia', 'number' => '55102938', 'amount' => 195000.00, 'days_due' => 8, 'issuer' => 'Parrilla El Fogón SRL', 'cuit' => '30712345671', 'status' => 'in_wallet'],
                ['bank' => 'Banco Macro', 'number' => '33981245', 'amount' => 120000.00, 'days_due' => 15, 'issuer' => 'Comedor La Estación', 'cuit' => '30659821431', 'status' => 'in_wallet'],
            ],
            'delivery_notes' => false,
        ];
    }

    private static function hardwareStoreCatalog(): array
    {
        return [
            'categories' => [
                ['key' => 'maderas', 'name' => 'Maderas y Machimbres', 'description' => 'Machimbres, tirantes y placas con cálculo F7'],
                ['key' => 'chapas', 'name' => 'Chapas y Techos', 'description' => 'Chapas galvanizadas, trapezoidales y perfiles'],
                ['key' => 'pintura', 'name' => 'Pinturería y Membranas', 'description' => 'Látex, esmaltes e impermeabilizantes'],
                ['key' => 'construccion', 'name' => 'Construcción y Fijaciones', 'description' => 'Cementos, clavos, tornillos y cables'],
            ],
            'brands' => ['Alto Paraná', 'Ternium Siderar', 'Alba', 'Loma Negra', 'TEL Fijaciones'],
            'products' => [
                ['code' => 'HW101', 'sku' => 'PIN-1243', 'name' => 'Machimbre Pino 1/2" x 4" x 3.00m (x Tabla)', 'cat' => 'maderas', 'brand' => 'Alto Paraná', 'cost' => 2100, 'price' => 3450, 'stock' => 350, 'min_stock' => 40, 'unit_type' => 'un', 'weight' => false, 'spec' => '1/2" x 4" x 3.00m', 'loc' => 'Galpón Maderas A1', 'iva' => 21.00, 'calc_config' => ['type' => 'wood', 'inch_preset' => '4', 'nominal_width_m' => 0.1016, 'useful_width_m' => 0.088, 'length_m' => 3.00, 'pricing_mode' => 'per_board', 'default_waste_pct' => 10]],
                ['code' => 'HW102', 'sku' => 'PIN-1253', 'name' => 'Machimbre Pino 1/2" x 5" x 3.60m (x m²)', 'cat' => 'maderas', 'brand' => 'Alto Paraná', 'cost' => 6800, 'price' => 10900, 'stock' => 280, 'min_stock' => 30, 'unit_type' => 'm2', 'weight' => true, 'spec' => '1/2" x 5" x 3.60m', 'loc' => 'Galpón Maderas A2', 'iva' => 21.00, 'calc_config' => ['type' => 'wood', 'inch_preset' => '5', 'nominal_width_m' => 0.127, 'useful_width_m' => 0.112, 'length_m' => 3.60, 'pricing_mode' => 'per_m2', 'm2_billing_basis' => 'nominal', 'default_waste_pct' => 10]],
                ['code' => 'HW103', 'sku' => 'PIN-PK10', 'name' => 'Machimbre Pino 1/2" x 4" x 2.40m (Paquete x 10)', 'cat' => 'maderas', 'brand' => 'Alto Paraná', 'cost' => 17500, 'price' => 27900, 'stock' => 65, 'min_stock' => 10, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Pack x10 Tablas', 'loc' => 'Galpón Maderas A3', 'iva' => 21.00, 'calc_config' => ['type' => 'wood', 'inch_preset' => '4', 'nominal_width_m' => 0.1016, 'useful_width_m' => 0.088, 'length_m' => 2.40, 'pricing_mode' => 'per_pack', 'boards_per_pack' => 10, 'round_to_full_pack' => true, 'default_waste_pct' => 5]],
                ['code' => 'HW104', 'sku' => 'EUC-3464', 'name' => 'Machimbre Eucalipto 3/4" x 6" x 4.00m (x Metro Lineal)', 'cat' => 'maderas', 'brand' => 'Alto Paraná', 'cost' => 1450, 'price' => 2350, 'stock' => 520, 'min_stock' => 80, 'unit_type' => 'mt', 'weight' => true, 'spec' => '3/4" x 6" x 4.00m', 'loc' => 'Galpón Maderas B1', 'iva' => 21.00, 'calc_config' => ['type' => 'wood', 'inch_preset' => '6', 'nominal_width_m' => 0.1524, 'useful_width_m' => 0.137, 'length_m' => 4.00, 'pricing_mode' => 'per_linear_meter', 'default_waste_pct' => 10]],
                ['code' => 'HW105', 'sku' => 'TIR-2230', 'name' => 'Tirante Pino Cepillado 2" x 2" x 3.00m (Alfajía)', 'cat' => 'maderas', 'brand' => 'Alto Paraná', 'cost' => 2400, 'price' => 3900, 'stock' => 210, 'min_stock' => 25, 'unit_type' => 'un', 'weight' => false, 'spec' => '2" x 2" x 3.00m', 'loc' => 'Galpón Maderas B2', 'iva' => 21.00],
                ['code' => 'HW106', 'sku' => 'DCK-1430', 'name' => 'Deck Pino Impregnado 1" x 4" x 3.00m', 'cat' => 'maderas', 'brand' => 'Alto Paraná', 'cost' => 3800, 'price' => 6200, 'stock' => 180, 'min_stock' => 20, 'unit_type' => 'un', 'weight' => false, 'spec' => '1" x 4" x 3.00m', 'loc' => 'Galpón Maderas B3', 'iva' => 21.00],
                ['code' => 'HW201', 'sku' => 'TER-C2745', 'name' => 'Chapa Acanalada Galvanizada C27 1.10 x 4.50m', 'cat' => 'chapas', 'brand' => 'Ternium Siderar', 'cost' => 28500, 'price' => 42900, 'stock' => 85, 'min_stock' => 10, 'unit_type' => 'un', 'weight' => false, 'spec' => 'C27 · 1.10x4.50m', 'loc' => 'Nave Techos 1', 'iva' => 21.00, 'calc_config' => ['type' => 'roof', 'nominal_width_m' => 1.10, 'useful_width_m' => 1.00, 'length_m' => 4.50, 'longitudinal_overlap_m' => 0.20, 'pricing_mode' => 'per_sheet']],
                ['code' => 'HW202', 'sku' => 'TER-C2560', 'name' => 'Chapa Trapezoidal C25 Prepintada Negra 1.10 x 6.00m', 'cat' => 'chapas', 'brand' => 'Ternium Siderar', 'cost' => 46000, 'price' => 68500, 'stock' => 40, 'min_stock' => 6, 'unit_type' => 'un', 'weight' => false, 'spec' => 'C25 · 1.10x6.00m', 'loc' => 'Nave Techos 2', 'iva' => 21.00, 'calc_config' => ['type' => 'roof', 'nominal_width_m' => 1.10, 'useful_width_m' => 1.00, 'length_m' => 6.00, 'longitudinal_overlap_m' => 0.20, 'pricing_mode' => 'per_sheet']],
                ['code' => 'HW203', 'sku' => 'TER-ALUM', 'name' => 'Chapa Acanalada C27 Aluminizada (x Metro Lineal)', 'cat' => 'chapas', 'brand' => 'Ternium Siderar', 'cost' => 6400, 'price' => 9800, 'stock' => 320, 'min_stock' => 30, 'unit_type' => 'mt', 'weight' => true, 'spec' => 'C27 · x Metro', 'loc' => 'Nave Techos 3', 'iva' => 21.00, 'calc_config' => ['type' => 'roof', 'nominal_width_m' => 1.10, 'useful_width_m' => 1.00, 'length_m' => 3.50, 'longitudinal_overlap_m' => 0.20, 'pricing_mode' => 'per_linear_meter']],
                ['code' => 'HW204', 'sku' => 'TER-PFC100', 'name' => 'Perfil C Galvanizado 100x50x15x2mm x 6.00m', 'cat' => 'chapas', 'brand' => 'Ternium Siderar', 'cost' => 24500, 'price' => 37800, 'stock' => 50, 'min_stock' => 8, 'unit_type' => 'un', 'weight' => false, 'spec' => '100x50x2mm x 6m', 'loc' => 'Rack Hierros 1', 'iva' => 21.00],
                ['code' => 'HW301', 'sku' => 'ALB-LAT04', 'name' => 'Pintura Látex Interior Mate Alba 4L', 'cat' => 'pintura', 'brand' => 'Alba', 'cost' => 14500, 'price' => 22900, 'stock' => 45, 'min_stock' => 6, 'unit_type' => 'un', 'weight' => false, 'spec' => '4 Litros Mate', 'loc' => 'Pasillo Pintura 1', 'iva' => 21.00, 'calc_config' => ['type' => 'paint', 'yield_m2_per_liter' => 10.0, 'yield_mode' => 'per_coat', 'container_liters' => 4.0, 'default_coats' => 2]],
                ['code' => 'HW302', 'sku' => 'ALB-LAT20', 'name' => 'Pintura Látex Exterior Lavable Alba 20L', 'cat' => 'pintura', 'brand' => 'Alba', 'cost' => 58000, 'price' => 89500, 'stock' => 22, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => '20 Litros Ext.', 'loc' => 'Pasillo Pintura 2', 'iva' => 21.00, 'calc_config' => ['type' => 'paint', 'yield_m2_per_liter' => 10.0, 'yield_mode' => 'per_coat', 'container_liters' => 20.0, 'default_coats' => 2]],
                ['code' => 'HW303', 'sku' => 'ALB-ESM01', 'name' => 'Esmalte Sintético Blanco Brillante 1L', 'cat' => 'pintura', 'brand' => 'Alba', 'cost' => 6200, 'price' => 9900, 'stock' => 60, 'min_stock' => 8, 'unit_type' => 'un', 'weight' => false, 'spec' => '1 Litro Brill.', 'loc' => 'Pasillo Pintura 1', 'iva' => 21.00, 'calc_config' => ['type' => 'paint', 'yield_m2_per_liter' => 12.0, 'yield_mode' => 'per_coat', 'container_liters' => 1.0, 'default_coats' => 2]],
                ['code' => 'HW304', 'sku' => 'ALB-MEM20', 'name' => 'Membrana Líquida Fibrada Techos 20Kg', 'cat' => 'pintura', 'brand' => 'Alba', 'cost' => 64000, 'price' => 96500, 'stock' => 3, 'min_stock' => 5, 'unit_type' => 'un', 'weight' => false, 'spec' => '20 Kg Fibrada', 'loc' => 'Pasillo Pintura 3', 'iva' => 21.00, 'calc_config' => ['type' => 'paint', 'yield_m2_per_liter' => 1.0, 'yield_mode' => 'final_total', 'container_liters' => 20.0, 'default_coats' => 3]],
                ['code' => 'HW305', 'sku' => 'ALB-ROD22', 'name' => 'Rodillo Antigota Lana Sintética 22cm', 'cat' => 'pintura', 'brand' => 'Alba', 'cost' => 3900, 'price' => 6500, 'stock' => 35, 'min_stock' => 5, 'unit_type' => 'un', 'weight' => false, 'spec' => '22 cm', 'loc' => 'Gaveta Pintor', 'iva' => 21.00],
                ['code' => 'HW306', 'sku' => 'ALB-CIN24', 'name' => 'Cinta de Enmascarar Pintor 24mm x 50m', 'cat' => 'pintura', 'brand' => 'Alba', 'cost' => 1400, 'price' => 2450, 'stock' => 80, 'min_stock' => 12, 'unit_type' => 'un', 'weight' => false, 'spec' => '24mm x 50m', 'loc' => 'Mostrador', 'iva' => 21.00],
                ['code' => 'HW401', 'sku' => 'LOM-50KG', 'name' => 'Cemento Portland Loma Negra 50Kg', 'cat' => 'construccion', 'brand' => 'Loma Negra', 'cost' => 7800, 'price' => 10900, 'stock' => 160, 'min_stock' => 30, 'unit_type' => 'bolsa', 'weight' => false, 'spec' => 'Bolsa 50 Kg', 'loc' => 'Depósito Material', 'iva' => 21.00],
                ['code' => 'HW402', 'sku' => 'LOM-CAL25', 'name' => 'Cal Hidratada Milagro 25Kg', 'cat' => 'construccion', 'brand' => 'Loma Negra', 'cost' => 3900, 'price' => 5800, 'stock' => 95, 'min_stock' => 20, 'unit_type' => 'bolsa', 'weight' => false, 'spec' => 'Bolsa 25 Kg', 'loc' => 'Depósito Material', 'iva' => 21.00],
                ['code' => 'HW403', 'sku' => 'TEL-CLV15', 'name' => 'Clavo Espiralado p/ Machimbre 1 1/2" (x Kg)', 'cat' => 'construccion', 'brand' => 'TEL Fijaciones', 'cost' => 3200, 'price' => 5400, 'stock' => 45, 'min_stock' => 5, 'unit_type' => 'kg', 'weight' => true, 'spec' => '1 1/2" Espiral.', 'loc' => 'Pasillo 2 - Gaveta B4', 'iva' => 21.00],
                ['code' => 'HW404', 'sku' => 'TEL-AUT14', 'name' => 'Tornillo Autoperforante Hexagonal 14x2" (x 100u)', 'cat' => 'construccion', 'brand' => 'TEL Fijaciones', 'cost' => 5500, 'price' => 8900, 'stock' => 90, 'min_stock' => 10, 'unit_type' => 'caja', 'weight' => false, 'spec' => '14x2" c/ Arandela', 'loc' => 'Pasillo 2 - Gaveta B6', 'iva' => 21.00],
                ['code' => 'HW405', 'sku' => 'TEL-T2P100', 'name' => 'Tornillo T2 Punta Aguja Drywall (x Kg)', 'cat' => 'construccion', 'brand' => 'TEL Fijaciones', 'cost' => 4200, 'price' => 6800, 'stock' => 4, 'min_stock' => 8, 'unit_type' => 'kg', 'weight' => true, 'spec' => '6x1" Negro', 'loc' => 'Pasillo 2 - Gaveta B2', 'iva' => 21.00],
                ['code' => 'HW406', 'sku' => 'CAB-25IR', 'name' => 'Cable Unipolar Sintenax/IRAM 2.5mm (x Metro)', 'cat' => 'construccion', 'brand' => 'TEL Fijaciones', 'cost' => 650, 'price' => 1050, 'stock' => 400, 'min_stock' => 50, 'unit_type' => 'mt', 'weight' => true, 'spec' => '2.5mm Normalizado', 'loc' => 'Rollo Eléctrico 1', 'iva' => 21.00],
                ['code' => 'HW407', 'sku' => 'HIE-8MM12', 'name' => 'Hierro Aletado Construcción 8mm x 12m', 'cat' => 'construccion', 'brand' => 'Loma Negra', 'cost' => 6900, 'price' => 9800, 'stock' => 140, 'min_stock' => 25, 'unit_type' => 'un', 'weight' => false, 'spec' => '8mm x 12m', 'loc' => 'Rack Hierros 2', 'iva' => 21.00],
                ['code' => 'HW408', 'sku' => 'DIS-COR115', 'name' => 'Disco de Corte Metal/Inox 115mm x1mm', 'cat' => 'construccion', 'brand' => 'TEL Fijaciones', 'cost' => 1150, 'price' => 1950, 'stock' => 4, 'min_stock' => 15, 'unit_type' => 'un', 'weight' => false, 'spec' => '115mm x 1mm', 'loc' => 'Tablero Herram.', 'iva' => 21.00],
            ],
            'combo' => [
                'name' => 'Kit Pintor Completo (Látex 20L + Rodillo 22cm + Cinta Enmascarar)',
                'internal_code' => 'HW-CMB01',
                'selling_price' => 94900.00,
                'category_key' => 'pintura',
                'brand' => 'Alba',
                'items' => [
                    ['code' => 'HW302', 'qty' => 1],
                    ['code' => 'HW305', 'qty' => 1],
                    ['code' => 'HW306', 'qty' => 2],
                ],
            ],
            'offers' => [
                ['name' => 'Obra Seca & Techos (-10%)', 'description' => 'Promoción en chapas seleccionadas y fijaciones', 'discount_percentage' => 10.00, 'product_codes' => ['HW201', 'HW404']],
                ['name' => 'Temporada Pinturería (-12%)', 'description' => 'Descuento contado en látex interior y esmalte', 'discount_percentage' => 12.00, 'product_codes' => ['HW301', 'HW303']],
            ],
            'quotes' => [
                ['status' => 'pending', 'customer_name' => 'Construcciones & Servicios del Norte SA', 'customer_phone' => '11-4899-3322', 'notes' => 'Cubierta techo tinglado 8x12m calculada con Calculadora F7.', 'price_list' => 'mayorista', 'days_offset' => 15, 'items' => [['code' => 'HW201', 'qty' => 18], ['code' => 'HW204', 'qty' => 12], ['code' => 'HW404', 'qty' => 4]]],
                ['status' => 'approved', 'customer_name' => 'Obra Barrio San Francisco - Arq. Sosa', 'customer_phone' => '3704-559922', 'notes' => 'Cielorraso quincho 45m² con tirantes y clavos.', 'price_list' => 'mayorista', 'days_offset' => 10, 'items' => [['code' => 'HW101', 'qty' => 160], ['code' => 'HW105', 'qty' => 24], ['code' => 'HW403', 'qty' => 4]]],
                ['status' => 'pending', 'customer_name' => 'Consorcio Edificio Mitre', 'customer_phone' => '3704-448811', 'notes' => 'Impermeabilización terraza 120m² + pintura pasillos.', 'price_list' => 'base', 'days_offset' => 12, 'items' => [['code' => 'HW304', 'qty' => 6], ['code' => 'HW302', 'qty' => 2]]],
                ['status' => 'expired', 'customer_name' => 'Taller Mecánico El Rayo', 'customer_phone' => '3704-429900', 'notes' => 'Cotización de perfil C vencida.', 'price_list' => 'base', 'days_offset' => -4, 'items' => [['code' => 'HW204', 'qty' => 8]]],
            ],
            'checks' => [
                ['bank' => 'Banco Galicia', 'number' => '44892104', 'amount' => 350000.00, 'days_due' => 5, 'issuer' => 'Construcciones & Servicios del Norte SA', 'cuit' => '30659821431', 'status' => 'in_wallet'],
                ['bank' => 'Banco Santander', 'number' => '11982344', 'amount' => 245000.00, 'days_due' => 14, 'issuer' => 'Supermercado Los Primos SRL', 'cuit' => '30712345671', 'status' => 'in_wallet'],
                ['bank' => 'Banco Ciudad', 'number' => '77123901', 'amount' => 420000.00, 'days_due' => 10, 'issuer' => 'Obras Civiles del Litoral', 'cuit' => '30714589233', 'status' => 'endorsed'],
            ],
            'delivery_notes' => true,
        ];
    }

    private static function apparelCatalog(): array
    {
        return [
            'categories' => [
                ['key' => 'remeras', 'name' => 'Remeras y Chombas', 'description' => 'Remeras básicas, estampadas y chombas'],
                ['key' => 'pantalones', 'name' => 'Jeans y Pantalones', 'description' => 'Denim, joggers y pantalones de vestir'],
                ['key' => 'abrigos', 'name' => 'Buzos y Camperas', 'description' => 'Buzos frizados, camperas livianas y rompevientos'],
                ['key' => 'calzado', 'name' => 'Calzado Urbano y Deportivo', 'description' => 'Zapatillas y borcegos por talle'],
                ['key' => 'accesorios', 'name' => 'Accesorios y Ropa Interior', 'description' => 'Medias, gorras, cinturones y mochilas'],
            ],
            'brands' => ['Taverniti', 'Legacy', 'Topper', 'Atomik', 'Kevingston'],
            'products' => [
                ['code' => 'IND101', 'name' => 'Remera Algodón Peinado Lisa', 'cat' => 'remeras', 'brand' => 'Legacy', 'cost' => 6900, 'price' => 12900, 'stock' => 24, 'min_stock' => 5, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Talle M · Negro', 'loc' => 'Perchero A1', 'iva' => 21.00],
                ['code' => 'IND102', 'name' => 'Remera Algodón Peinado Lisa', 'cat' => 'remeras', 'brand' => 'Legacy', 'cost' => 6900, 'price' => 12900, 'stock' => 18, 'min_stock' => 5, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Talle L · Negro', 'loc' => 'Perchero A1', 'iva' => 21.00],
                ['code' => 'IND103', 'name' => 'Remera Algodón Peinado Lisa', 'cat' => 'remeras', 'brand' => 'Legacy', 'cost' => 6900, 'price' => 12900, 'stock' => 3, 'min_stock' => 5, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Talle XL · Blanco', 'loc' => 'Perchero A2', 'iva' => 21.00],
                ['code' => 'IND104', 'name' => 'Chomba Piqué Clásica Bordada', 'cat' => 'remeras', 'brand' => 'Kevingston', 'cost' => 13500, 'price' => 24500, 'stock' => 16, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Talle L · Azul Marino', 'loc' => 'Perchero A3', 'iva' => 21.00],
                ['code' => 'IND105', 'name' => 'Remera Oversize Estampada Urbana', 'cat' => 'remeras', 'brand' => 'Legacy', 'cost' => 8900, 'price' => 16500, 'stock' => 20, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Talle M · Arena', 'loc' => 'Mesa Central 1', 'iva' => 21.00],
                ['code' => 'IND201', 'name' => 'Jean Clásico Recto Elastizado', 'cat' => 'pantalones', 'brand' => 'Taverniti', 'cost' => 21500, 'price' => 38900, 'stock' => 14, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Talle 42 · Azul Índigo', 'loc' => 'Estante Jeans 1', 'iva' => 21.00],
                ['code' => 'IND202', 'name' => 'Jean Clásico Recto Elastizado', 'cat' => 'pantalones', 'brand' => 'Taverniti', 'cost' => 21500, 'price' => 38900, 'stock' => 12, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Talle 44 · Azul Índigo', 'loc' => 'Estante Jeans 1', 'iva' => 21.00],
                ['code' => 'IND203', 'name' => 'Jogger Cargo Gabardina Slim', 'cat' => 'pantalones', 'brand' => 'Taverniti', 'cost' => 18900, 'price' => 33900, 'stock' => 15, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Talle L · Verde Oliva', 'loc' => 'Perchero B2', 'iva' => 21.00],
                ['code' => 'IND204', 'name' => 'Pantalón Chino Gabardina Vestir', 'cat' => 'pantalones', 'brand' => 'Kevingston', 'cost' => 22900, 'price' => 41500, 'stock' => 10, 'min_stock' => 3, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Talle 42 · Beige', 'loc' => 'Perchero B3', 'iva' => 21.00],
                ['code' => 'IND301', 'name' => 'Buzo Canguro Friza Invisible', 'cat' => 'abrigos', 'brand' => 'Kevingston', 'cost' => 17500, 'price' => 31900, 'stock' => 14, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Talle M · Gris Melange', 'loc' => 'Perchero C1', 'iva' => 21.00],
                ['code' => 'IND302', 'name' => 'Buzo Canguro Friza Invisible', 'cat' => 'abrigos', 'brand' => 'Kevingston', 'cost' => 17500, 'price' => 31900, 'stock' => 2, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Talle L · Negro', 'loc' => 'Perchero C1', 'iva' => 21.00],
                ['code' => 'IND303', 'name' => 'Campera Puffer Liviana Matelaseada', 'cat' => 'abrigos', 'brand' => 'Legacy', 'cost' => 32000, 'price' => 56900, 'stock' => 8, 'min_stock' => 2, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Talle L · Negro', 'loc' => 'Perchero C2', 'iva' => 21.00],
                ['code' => 'IND304', 'name' => 'Campera Rompeviento Deportiva', 'cat' => 'abrigos', 'brand' => 'Topper', 'cost' => 26000, 'price' => 46500, 'stock' => 9, 'min_stock' => 3, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Talle M · Azul/Blanco', 'loc' => 'Perchero C3', 'iva' => 21.00],
                ['code' => 'IND401', 'name' => 'Zapatillas Urbanas Lona Clásicas', 'cat' => 'calzado', 'brand' => 'Topper', 'cost' => 24500, 'price' => 43900, 'stock' => 10, 'min_stock' => 3, 'unit_type' => 'par', 'weight' => false, 'spec' => 'Calz. 40 · Blanco', 'loc' => 'Depósito Caja 1', 'iva' => 21.00],
                ['code' => 'IND402', 'name' => 'Zapatillas Urbanas Lona Clásicas', 'cat' => 'calzado', 'brand' => 'Topper', 'cost' => 24500, 'price' => 43900, 'stock' => 12, 'min_stock' => 3, 'unit_type' => 'par', 'weight' => false, 'spec' => 'Calz. 41 · Negro', 'loc' => 'Depósito Caja 2', 'iva' => 21.00],
                ['code' => 'IND403', 'name' => 'Zapatillas Running Amortiguadas', 'cat' => 'calzado', 'brand' => 'Atomik', 'cost' => 29500, 'price' => 52900, 'stock' => 8, 'min_stock' => 2, 'unit_type' => 'par', 'weight' => false, 'spec' => 'Calz. 42 · Gris/Azul', 'loc' => 'Depósito Caja 4', 'iva' => 21.00],
                ['code' => 'IND404', 'name' => 'Ojotas Anatómicas Verano', 'cat' => 'calzado', 'brand' => 'Atomik', 'cost' => 5800, 'price' => 10900, 'stock' => 18, 'min_stock' => 4, 'unit_type' => 'par', 'weight' => false, 'spec' => 'Calz. 39-40 · Negro', 'loc' => 'Exhibidor Calzado', 'iva' => 21.00],
                ['code' => 'IND501', 'name' => 'Medias Soquete Deportivas', 'cat' => 'accesorios', 'brand' => 'Topper', 'cost' => 1500, 'price' => 2900, 'stock' => 45, 'min_stock' => 10, 'unit_type' => 'par', 'weight' => false, 'spec' => 'Talle Único · Blanco', 'loc' => 'Cajón Mostrador 1', 'iva' => 21.00],
                ['code' => 'IND502', 'name' => 'Boxer Algodón con Lycra', 'cat' => 'accesorios', 'brand' => 'Legacy', 'cost' => 3400, 'price' => 6400, 'stock' => 2, 'min_stock' => 6, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Talle L · Surtido', 'loc' => 'Cajón Mostrador 2', 'iva' => 21.00],
                ['code' => 'IND503', 'name' => 'Gorra Gabardina Visera Curva', 'cat' => 'accesorios', 'brand' => 'Kevingston', 'cost' => 4600, 'price' => 8900, 'stock' => 16, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Único · Negra', 'loc' => 'Exhibidor Caja', 'iva' => 21.00],
                ['code' => 'IND504', 'name' => 'Cinturón Cuero Ecológico Hebilla Metálica', 'cat' => 'accesorios', 'brand' => 'Taverniti', 'cost' => 5200, 'price' => 9800, 'stock' => 14, 'min_stock' => 3, 'unit_type' => 'un', 'weight' => false, 'spec' => '110cm · Marrón', 'loc' => 'Exhibidor Caja', 'iva' => 21.00],
                ['code' => 'IND505', 'name' => 'Mochila Urbana Porta Notebook 15.6"', 'cat' => 'accesorios', 'brand' => 'Atomik', 'cost' => 14500, 'price' => 26900, 'stock' => 10, 'min_stock' => 3, 'unit_type' => 'un', 'weight' => false, 'spec' => '22L · Negra', 'loc' => 'Estante Superior', 'iva' => 21.00],
            ],
            'combo' => [
                'name' => 'Outfit Urbano (Remera Lisa M + Jean Clásico 42 + Pack Medias)',
                'internal_code' => 'IND-CMB01',
                'selling_price' => 49900.00,
                'category_key' => 'remeras',
                'brand' => 'Legacy',
                'items' => [
                    ['code' => 'IND101', 'qty' => 1],
                    ['code' => 'IND201', 'qty' => 1],
                    ['code' => 'IND501', 'qty' => 1],
                ],
            ],
            'offers' => [
                ['name' => '2da Unidad al 50% en Remeras (25% OFF)', 'description' => 'Descuento directo en remeras básicas y oversize', 'discount_percentage' => 20.00, 'product_codes' => ['IND101', 'IND102', 'IND105']],
                ['name' => 'Liquidación Abrigos (-15%)', 'description' => 'Promo anticipada en buzos canguro', 'discount_percentage' => 15.00, 'product_codes' => ['IND301', 'IND302']],
            ],
            'quotes' => [
                ['status' => 'pending', 'customer_name' => 'Equipo Futsal San Martín', 'customer_phone' => '3704-511200', 'notes' => 'Equipamiento indumentaria uniforme plantel (12 conjuntos).', 'price_list' => 'mayorista', 'days_offset' => 10, 'items' => [['code' => 'IND101', 'qty' => 12], ['code' => 'IND501', 'qty' => 12]]],
                ['status' => 'approved', 'customer_name' => 'Empresa Logística Norte', 'customer_phone' => '3704-482210', 'notes' => 'Uniformes chombas y camperas personal depósito.', 'price_list' => 'mayorista', 'days_offset' => 7, 'items' => [['code' => 'IND104', 'qty' => 8], ['code' => 'IND304', 'qty' => 8]]],
                ['status' => 'expired', 'customer_name' => 'Colegio Secundario Egreso', 'customer_phone' => '3704-439900', 'notes' => 'Presupuesto buzos promoción vencido.', 'price_list' => 'mayorista', 'days_offset' => -5, 'items' => [['code' => 'IND301', 'qty' => 20]]],
            ],
            'checks' => [
                ['bank' => 'Banco Galicia', 'number' => '66109234', 'amount' => 165000.00, 'days_due' => 9, 'issuer' => 'Uniformes del Litoral SRL', 'cuit' => '30712345671', 'status' => 'in_wallet'],
                ['bank' => 'Banco Santander', 'number' => '22334455', 'amount' => 98000.00, 'days_due' => 18, 'issuer' => 'Distribuidora Textil SA', 'cuit' => '30659821431', 'status' => 'in_wallet'],
            ],
            'delivery_notes' => false,
        ];
    }

    private static function techCatalog(): array
    {
        return [
            'categories' => [
                ['key' => 'celulares', 'name' => 'Equipos y Smartphones', 'description' => 'Teléfonos libres en caja sellada'],
                ['key' => 'repuestos', 'name' => 'Repuestos y Módulos', 'description' => 'Pantallas OLED, baterías, pines de carga y repuestos'],
                ['key' => 'accesorios', 'name' => 'Accesorios y Carga', 'description' => 'Cargadores, cables, fundas y templados'],
                ['key' => 'audio_pc', 'name' => 'Audio, Periféricos y Cables', 'description' => 'Auriculares, SSD, mouse y cables de red por metro'],
                ['key' => 'servicios', 'name' => 'Servicios Técnicos', 'description' => 'Mano de obra de laboratorio, diagnósticos e instalaciones'],
            ],
            'brands' => ['Samsung', 'Xiaomi', 'Kingston', 'Logitech', 'Genérico OEM'],
            'products' => [
                ['code' => 'TEC101', 'sku' => 'SAM-A15-128', 'name' => 'Smartphone Samsung Galaxy A15 128GB', 'cat' => 'celulares', 'brand' => 'Samsung', 'cost' => 215000, 'price' => 299000, 'stock' => 6, 'min_stock' => 2, 'unit_type' => 'un', 'weight' => false, 'spec' => '128GB · 4GB RAM · Negro', 'loc' => 'Vitrina 1 - Caja Fuerte', 'iva' => 21.00],
                ['code' => 'TEC102', 'sku' => 'XIA-RN13-256', 'name' => 'Smartphone Xiaomi Redmi Note 13 256GB', 'cat' => 'celulares', 'brand' => 'Xiaomi', 'cost' => 245000, 'price' => 339000, 'stock' => 5, 'min_stock' => 2, 'unit_type' => 'un', 'weight' => false, 'spec' => '256GB · 8GB RAM', 'loc' => 'Vitrina 1 - Caja Fuerte', 'iva' => 21.00],
                ['code' => 'TEC103', 'sku' => 'SAM-A05-64', 'name' => 'Smartphone Samsung Galaxy A05 64GB', 'cat' => 'celulares', 'brand' => 'Samsung', 'cost' => 145000, 'price' => 199000, 'stock' => 8, 'min_stock' => 2, 'unit_type' => 'un', 'weight' => false, 'spec' => '64GB · 4GB RAM', 'loc' => 'Vitrina 1', 'iva' => 21.00],
                ['code' => 'TEC201', 'sku' => 'MOD-A15-OLED', 'name' => 'Módulo Pantalla Completo Samsung A15', 'cat' => 'repuestos', 'brand' => 'Samsung', 'cost' => 34000, 'price' => 56000, 'stock' => 7, 'min_stock' => 2, 'unit_type' => 'un', 'weight' => false, 'spec' => 'OLED Con Marco', 'loc' => 'Gaveta Taller A1', 'iva' => 21.00],
                ['code' => 'TEC202', 'sku' => 'MOD-MOT-G32', 'name' => 'Módulo Display Motorola Moto G32', 'cat' => 'repuestos', 'brand' => 'Genérico OEM', 'cost' => 26000, 'price' => 44000, 'stock' => 5, 'min_stock' => 2, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Incell Calidad A+', 'loc' => 'Gaveta Taller A2', 'iva' => 21.00],
                ['code' => 'TEC203', 'sku' => 'BAT-SAM-A51', 'name' => 'Batería Interna Samsung A51 / A52', 'cat' => 'repuestos', 'brand' => 'Samsung', 'cost' => 11500, 'price' => 21000, 'stock' => 2, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => '4000mAh OEM', 'loc' => 'Gaveta Taller B1', 'iva' => 21.00],
                ['code' => 'TEC204', 'sku' => 'PIN-USBC-UNI', 'name' => 'Placa Sub-Board Pin de Carga USB-C', 'cat' => 'repuestos', 'brand' => 'Genérico OEM', 'cost' => 4200, 'price' => 9500, 'stock' => 14, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Carga Rápida', 'loc' => 'Gaveta Taller B3', 'iva' => 21.00],
                ['code' => 'TEC205', 'sku' => 'KIT-WAVE110', 'name' => 'Kit Transmisión / Regulador Moto 110cc', 'cat' => 'repuestos', 'brand' => 'Genérico OEM', 'cost' => 13800, 'price' => 23500, 'stock' => 9, 'min_stock' => 3, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Wave / Smash 110', 'loc' => 'Estante Repuestos 2', 'iva' => 21.00],
                ['code' => 'TEC301', 'sku' => 'CHG-25W-PD', 'name' => 'Cargador Turbo USB-C 25W Original', 'cat' => 'accesorios', 'brand' => 'Samsung', 'cost' => 11000, 'price' => 19500, 'stock' => 24, 'min_stock' => 6, 'unit_type' => 'un', 'weight' => false, 'spec' => '25W Super Fast', 'loc' => 'Panel Accesorios 1', 'iva' => 21.00],
                ['code' => 'TEC302', 'sku' => 'CBL-USBC-15', 'name' => 'Cable USB-C a USB-C Reforzado 1.5m', 'cat' => 'accesorios', 'brand' => 'Xiaomi', 'cost' => 3200, 'price' => 6200, 'stock' => 35, 'min_stock' => 8, 'unit_type' => 'un', 'weight' => false, 'spec' => '1.5m · 60W', 'loc' => 'Panel Accesorios 1', 'iva' => 21.00],
                ['code' => 'TEC303', 'sku' => 'VID-9D-UNI', 'name' => 'Vidrio Templado 9D Full Glue (Surtido)', 'cat' => 'accesorios', 'brand' => 'Genérico OEM', 'cost' => 1200, 'price' => 3900, 'stock' => 65, 'min_stock' => 15, 'unit_type' => 'un', 'weight' => false, 'spec' => '9D Borde Negro', 'loc' => 'Cajón Templados', 'iva' => 21.00],
                ['code' => 'TEC304', 'sku' => 'FND-SIL-ANTI', 'name' => 'Funda Silicona Antigolpe Transparente', 'cat' => 'accesorios', 'brand' => 'Genérico OEM', 'cost' => 1900, 'price' => 4900, 'stock' => 4, 'min_stock' => 10, 'unit_type' => 'un', 'weight' => false, 'spec' => 'TPU Reforzado', 'loc' => 'Panel Fundas', 'iva' => 21.00],
                ['code' => 'TEC305', 'sku' => 'PWR-10000', 'name' => 'Power Bank 10.000 mAh Doble Salida', 'cat' => 'accesorios', 'brand' => 'Xiaomi', 'cost' => 16500, 'price' => 28900, 'stock' => 10, 'min_stock' => 3, 'unit_type' => 'un', 'weight' => false, 'spec' => '10000mAh · 18W', 'loc' => 'Vitrina 2', 'iva' => 21.00],
                ['code' => 'TEC401', 'sku' => 'KNG-SSD-480', 'name' => 'Disco Sólido SSD SATA 480GB', 'cat' => 'audio_pc', 'brand' => 'Kingston', 'cost' => 29500, 'price' => 46900, 'stock' => 12, 'min_stock' => 3, 'unit_type' => 'un', 'weight' => false, 'spec' => 'SATA III 2.5"', 'loc' => 'Vitrina PC', 'iva' => 21.00],
                ['code' => 'TEC402', 'sku' => 'KNG-PEN-64', 'name' => 'Pendrive USB 3.2 64GB Metálico', 'cat' => 'audio_pc', 'brand' => 'Kingston', 'cost' => 6400, 'price' => 10900, 'stock' => 22, 'min_stock' => 5, 'unit_type' => 'un', 'weight' => false, 'spec' => '64GB USB 3.2', 'loc' => 'Mostrador', 'iva' => 21.00],
                ['code' => 'TEC403', 'sku' => 'LOG-M170', 'name' => 'Mouse Inalámbrico Óptico M170', 'cat' => 'audio_pc', 'brand' => 'Logitech', 'cost' => 11200, 'price' => 18900, 'stock' => 15, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => '2.4GHz Receptor', 'loc' => 'Vitrina PC', 'iva' => 21.00],
                ['code' => 'TEC404', 'sku' => 'XIA-TWS-53', 'name' => 'Auriculares Inalámbricos Redmi Buds', 'cat' => 'audio_pc', 'brand' => 'Xiaomi', 'cost' => 15800, 'price' => 26900, 'stock' => 14, 'min_stock' => 3, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Bluetooth 5.3', 'loc' => 'Vitrina 2', 'iva' => 21.00],
                ['code' => 'TEC405', 'sku' => 'UTP-CAT6-MT', 'name' => 'Cable de Red UTP Cat6 Exterior (x Metro)', 'cat' => 'audio_pc', 'brand' => 'Genérico OEM', 'cost' => 490, 'price' => 950, 'stock' => 250, 'min_stock' => 40, 'unit_type' => 'mt', 'weight' => true, 'spec' => 'Cat6 100% Cobre', 'loc' => 'Bobina Depósito', 'iva' => 21.00],
                ['code' => 'TEC501', 'sku' => 'SRV-MOD-01', 'name' => 'Mano de Obra Cambio de Módulo / Pantalla', 'cat' => 'servicios', 'brand' => 'Genérico OEM', 'cost' => 0, 'price' => 22000, 'stock' => 0, 'min_stock' => 0, 'unit_type' => 'un', 'weight' => false, 'is_service' => true, 'spec' => 'Laboratorio 24hs', 'loc' => 'Box Técnico', 'iva' => 21.00],
                ['code' => 'TEC502', 'sku' => 'SRV-SSD-02', 'name' => 'Servicio Optimización PC + Migración a SSD', 'cat' => 'servicios', 'brand' => 'Genérico OEM', 'cost' => 0, 'price' => 25000, 'stock' => 0, 'min_stock' => 0, 'unit_type' => 'un', 'weight' => false, 'is_service' => true, 'spec' => 'Incluye Backup', 'loc' => 'Box Técnico', 'iva' => 21.00],
            ],
            'combo' => [
                'name' => 'Combo Protección Total (Cargador 25W + Vidrio 9D + Funda TPU)',
                'internal_code' => 'TEC-CMB01',
                'selling_price' => 25900.00,
                'category_key' => 'accesorios',
                'brand' => 'Samsung',
                'items' => [
                    ['code' => 'TEC301', 'qty' => 1],
                    ['code' => 'TEC303', 'qty' => 1],
                    ['code' => 'TEC304', 'qty' => 1],
                ],
            ],
            'offers' => [
                ['name' => 'Semana Gamer & PC (-10%)', 'description' => 'Descuento en SSD y periféricos', 'discount_percentage' => 10.00, 'product_codes' => ['TEC401', 'TEC403']],
                ['name' => 'Accesorios Móviles (-15%)', 'description' => 'Promo carga rápida y audio TWS', 'discount_percentage' => 15.00, 'product_codes' => ['TEC301', 'TEC404']],
            ],
            'quotes' => [
                ['status' => 'pending', 'customer_name' => 'Martín Acuña (Reparación A15)', 'customer_phone' => '3704-541122', 'notes' => 'Cambio de módulo OLED + vidrio templado + mano de obra.', 'price_list' => 'base', 'days_offset' => 7, 'items' => [['code' => 'TEC201', 'qty' => 1], ['code' => 'TEC501', 'qty' => 1], ['code' => 'TEC303', 'qty' => 1]]],
                ['status' => 'approved', 'customer_name' => 'Estudio Contable Romero', 'customer_phone' => '3704-428877', 'notes' => 'Actualización 4 PCs de oficina con SSD 480GB.', 'price_list' => 'mayorista', 'days_offset' => 10, 'items' => [['code' => 'TEC401', 'qty' => 4], ['code' => 'TEC502', 'qty' => 4]]],
                ['status' => 'pending', 'customer_name' => 'Instituto Educativo Norte', 'customer_phone' => '3704-441990', 'notes' => 'Cableado de red Cat6 aula informática (80m).', 'price_list' => 'base', 'days_offset' => 14, 'items' => [['code' => 'TEC405', 'qty' => 80], ['code' => 'TEC403', 'qty' => 6]]],
                ['status' => 'expired', 'customer_name' => 'Lucas Benítez', 'customer_phone' => '3704-433556', 'notes' => 'Cotización renovación equipo vencida.', 'price_list' => 'base', 'days_offset' => -3, 'items' => [['code' => 'TEC101', 'qty' => 1]]],
            ],
            'checks' => [
                ['bank' => 'Banco Galicia', 'number' => '99112233', 'amount' => 285000.00, 'days_due' => 10, 'issuer' => 'Estudio Contable Romero SRL', 'cuit' => '30712345671', 'status' => 'in_wallet'],
                ['bank' => 'Banco BBVA', 'number' => '66109234', 'amount' => 195000.00, 'days_due' => 18, 'issuer' => 'Instituto Educativo Norte', 'cuit' => '30659821431', 'status' => 'in_wallet'],
            ],
            'delivery_notes' => false,
        ];
    }

    private static function generalCatalog(): array
    {
        return [
            'categories' => [
                ['key' => 'libreria', 'name' => 'Librería Escolar y Oficina', 'description' => 'Resmas, cuadernos, lápices y útiles'],
                ['key' => 'bazar', 'name' => 'Bazar, Cocina y Termos', 'description' => 'Termos, mates, tuppers y vajilla'],
                ['key' => 'regaleria', 'name' => 'Regalería y Deco', 'description' => 'Tazas, velas, portarretratos y bolsas regalo'],
                ['key' => 'jugueteria', 'name' => 'Juguetería y Cotillón', 'description' => 'Juegos de mesa, pelotas y cotillón'],
            ],
            'brands' => ['Rivadavia', 'Faber-Castell', 'Stanley', 'Lumilagro', 'Tupperware'],
            'products' => [
                ['code' => 'GEN101', 'name' => 'Resma Papel A4 Autor 75g x500 Hojas', 'cat' => 'libreria', 'brand' => 'Rivadavia', 'cost' => 4800, 'price' => 7600, 'stock' => 45, 'min_stock' => 10, 'unit_type' => 'un', 'weight' => false, 'pkg' => 'Caja', 'conv' => 5, 'spec' => 'A4 · 75g x500', 'loc' => 'Góndola Papel 1', 'iva' => 21.00],
                ['code' => 'GEN102', 'name' => 'Cuaderno Tapa Dura N°3 Rayado 100 Hojas', 'cat' => 'libreria', 'brand' => 'Rivadavia', 'cost' => 3200, 'price' => 5400, 'stock' => 36, 'min_stock' => 8, 'unit_type' => 'un', 'weight' => false, 'pkg' => 'Docena', 'conv' => 12, 'spec' => '19x23cm · Azul', 'loc' => 'Góndola Escolar 1', 'iva' => 21.00],
                ['code' => 'GEN103', 'name' => 'Repuesto Hojas N°3 Rayadas x480', 'cat' => 'libreria', 'brand' => 'Rivadavia', 'cost' => 5900, 'price' => 9400, 'stock' => 28, 'min_stock' => 6, 'unit_type' => 'un', 'weight' => false, 'pkg' => 'Caja', 'conv' => 10, 'spec' => '480 Hojas N°3', 'loc' => 'Góndola Escolar 1', 'iva' => 21.00],
                ['code' => 'GEN104', 'name' => 'Lápices de Colores Largos x12', 'cat' => 'libreria', 'brand' => 'Faber-Castell', 'cost' => 2400, 'price' => 4200, 'stock' => 42, 'min_stock' => 10, 'unit_type' => 'caja', 'weight' => false, 'pkg' => 'Docena', 'conv' => 12, 'spec' => 'Caja x12 Eco', 'loc' => 'Góndola Escolar 2', 'iva' => 21.00],
                ['code' => 'GEN105', 'name' => 'Set Marcadores Resaltadores Pastel x6', 'cat' => 'libreria', 'brand' => 'Faber-Castell', 'cost' => 2900, 'price' => 4950, 'stock' => 3, 'min_stock' => 8, 'unit_type' => 'un', 'weight' => false, 'pkg' => 'Caja', 'conv' => 12, 'spec' => 'Pastel x6', 'loc' => 'Mostrador', 'iva' => 21.00],
                ['code' => 'GEN106', 'name' => 'Birome Azul Trazo Medio (x Unidad)', 'cat' => 'libreria', 'brand' => 'Faber-Castell', 'cost' => 350, 'price' => 750, 'stock' => 140, 'min_stock' => 25, 'unit_type' => 'un', 'weight' => false, 'pkg' => 'Caja', 'conv' => 50, 'spec' => '1.0mm Azul', 'loc' => 'Mostrador', 'iva' => 21.00],
                ['code' => 'GEN107', 'name' => 'Carpeta 3 Anillos Fibra Negra N°3', 'cat' => 'libreria', 'brand' => 'Rivadavia', 'cost' => 2600, 'price' => 4400, 'stock' => 24, 'min_stock' => 6, 'unit_type' => 'un', 'weight' => false, 'spec' => 'N°3 Negra', 'loc' => 'Góndola Escolar 2', 'iva' => 21.00],
                ['code' => 'GEN201', 'name' => 'Termo Acero Inoxidable 1L c/ Tapón Cebador', 'cat' => 'bazar', 'brand' => 'Stanley', 'cost' => 29500, 'price' => 46900, 'stock' => 12, 'min_stock' => 3, 'unit_type' => 'un', 'weight' => false, 'spec' => '1.0L · Verde', 'loc' => 'Vitrina Termos', 'iva' => 21.00],
                ['code' => 'GEN202', 'name' => 'Termo Acero Bala 1L Clásico', 'cat' => 'bazar', 'brand' => 'Lumilagro', 'cost' => 14200, 'price' => 23500, 'stock' => 18, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => '1.0L · Plata', 'loc' => 'Vitrina Termos', 'iva' => 21.00],
                ['code' => 'GEN203', 'name' => 'Mate Térmico Acero Inox + Bombilla', 'cat' => 'bazar', 'brand' => 'Lumilagro', 'cost' => 6800, 'price' => 11900, 'stock' => 22, 'min_stock' => 5, 'unit_type' => 'un', 'weight' => false, 'spec' => '240ml · Negro', 'loc' => 'Estante Mates', 'iva' => 21.00],
                ['code' => 'GEN204', 'name' => 'Set Herméticos Freezer x3 Tamaños', 'cat' => 'bazar', 'brand' => 'Tupperware', 'cost' => 7400, 'price' => 12800, 'stock' => 16, 'min_stock' => 4, 'unit_type' => 'un', 'weight' => false, 'spec' => '500ml/1L/1.8L', 'loc' => 'Góndola Cocina 1', 'iva' => 21.00],
                ['code' => 'GEN205', 'name' => 'Botella Deportiva Libre BPA 750ml', 'cat' => 'bazar', 'brand' => 'Tupperware', 'cost' => 3600, 'price' => 6500, 'stock' => 2, 'min_stock' => 6, 'unit_type' => 'un', 'weight' => false, 'spec' => '750ml · Turquesa', 'loc' => 'Góndola Cocina 2', 'iva' => 21.00],
                ['code' => 'GEN206', 'name' => 'Juego Vasos Vidrio Templado x6u', 'cat' => 'bazar', 'brand' => 'Tupperware', 'cost' => 5900, 'price' => 9800, 'stock' => 15, 'min_stock' => 4, 'unit_type' => 'caja', 'weight' => false, 'spec' => '320ml Pack x6', 'loc' => 'Góndola Vajilla', 'iva' => 21.00],
                ['code' => 'GEN301', 'name' => 'Taza Cerámica Sublimada Regalo c/ Caja', 'cat' => 'regaleria', 'brand' => 'Lumilagro', 'cost' => 2400, 'price' => 4900, 'stock' => 30, 'min_stock' => 6, 'unit_type' => 'un', 'weight' => false, 'spec' => '350ml · Diseño', 'loc' => 'Estante Regalos 1', 'iva' => 21.00],
                ['code' => 'GEN302', 'name' => 'Vela Aromática Vainilla en Frasco Vidrio', 'cat' => 'regaleria', 'brand' => 'Lumilagro', 'cost' => 2800, 'price' => 5400, 'stock' => 20, 'min_stock' => 5, 'unit_type' => 'un', 'weight' => false, 'spec' => '200g · Vainilla', 'loc' => 'Estante Regalos 2', 'iva' => 21.00],
                ['code' => 'GEN303', 'name' => 'Bolsa Kraft Regalo Mediana Pack x12', 'cat' => 'regaleria', 'brand' => 'Rivadavia', 'cost' => 2100, 'price' => 3900, 'stock' => 25, 'min_stock' => 5, 'unit_type' => 'docena', 'weight' => false, 'spec' => '24x32cm x12u', 'loc' => 'Mostrador', 'iva' => 21.00],
                ['code' => 'GEN401', 'name' => 'Juego de Mesa Clásico Familiar (TEG / Ludo)', 'cat' => 'jugueteria', 'brand' => 'Faber-Castell', 'cost' => 9800, 'price' => 16900, 'stock' => 10, 'min_stock' => 3, 'unit_type' => 'un', 'weight' => false, 'spec' => 'Edición Caja', 'loc' => 'Góndola Juguetería', 'iva' => 21.00],
                ['code' => 'GEN402', 'name' => 'Pelota Fútbol N°5 Costura Reforzada', 'cat' => 'jugueteria', 'brand' => 'Faber-Castell', 'cost' => 8900, 'price' => 15500, 'stock' => 12, 'min_stock' => 3, 'unit_type' => 'un', 'weight' => false, 'spec' => 'N°5 Cuero PU', 'loc' => 'Góndola Juguetería', 'iva' => 21.00],
                ['code' => 'GEN403', 'name' => 'Set Masa Moldeable x6 Potes + Cortantes', 'cat' => 'jugueteria', 'brand' => 'Faber-Castell', 'cost' => 3800, 'price' => 6900, 'stock' => 3, 'min_stock' => 6, 'unit_type' => 'un', 'weight' => false, 'spec' => 'No Tóxica x6', 'loc' => 'Góndola Juguetería', 'iva' => 21.00],
                ['code' => 'GEN404', 'name' => 'Pack Globos Perlados Surtidos x50u', 'cat' => 'jugueteria', 'brand' => 'Rivadavia', 'cost' => 1950, 'price' => 3600, 'stock' => 28, 'min_stock' => 6, 'unit_type' => 'bolsa', 'weight' => false, 'spec' => '12" x50u', 'loc' => 'Sector Cotillón', 'iva' => 21.00],
            ],
            'combo' => [
                'name' => 'Kit Matero Completo (Termo Bala 1L + Mate Térmico + Bombilla)',
                'internal_code' => 'GEN-CMB01',
                'selling_price' => 32900.00,
                'category_key' => 'bazar',
                'brand' => 'Lumilagro',
                'items' => [
                    ['code' => 'GEN202', 'qty' => 1],
                    ['code' => 'GEN203', 'qty' => 1],
                ],
            ],
            'offers' => [
                ['name' => 'Vuelta a Clases (-12%)', 'description' => 'Descuento en cuadernos, repuestos y lápices', 'discount_percentage' => 12.00, 'product_codes' => ['GEN102', 'GEN103', 'GEN104']],
                ['name' => 'Especial Regalos (-10%)', 'description' => 'Termos y mates seleccionados', 'discount_percentage' => 10.00, 'product_codes' => ['GEN202', 'GEN203']],
            ],
            'quotes' => [
                ['status' => 'pending', 'customer_name' => 'Colegio Privado San Martín', 'customer_phone' => '3704-441990', 'notes' => 'Resmas A4 y marcadores para sala docente.', 'price_list' => 'mayorista', 'days_offset' => 12, 'items' => [['code' => 'GEN101', 'qty' => 15], ['code' => 'GEN105', 'qty' => 10]]],
                ['status' => 'approved', 'customer_name' => 'Regalos Empresariales Norte', 'customer_phone' => '3704-552244', 'notes' => 'Set termos y mates grabados fin de año.', 'price_list' => 'mayorista', 'days_offset' => 8, 'items' => [['code' => 'GEN202', 'qty' => 10], ['code' => 'GEN203', 'qty' => 10]]],
                ['status' => 'expired', 'customer_name' => 'Librería El Estudiante', 'customer_phone' => '3704-466100', 'notes' => 'Reposición cuadernos vencida.', 'price_list' => 'base', 'days_offset' => -3, 'items' => [['code' => 'GEN102', 'qty' => 24]]],
            ],
            'checks' => [
                ['bank' => 'Banco Galicia', 'number' => '77221100', 'amount' => 185000.00, 'days_due' => 8, 'issuer' => 'Colegio Privado San Martín', 'cuit' => '30712345671', 'status' => 'in_wallet'],
                ['bank' => 'Banco Macro', 'number' => '33981245', 'amount' => 110000.00, 'days_due' => 15, 'issuer' => 'Oficinas del Centro SRL', 'cuit' => '30659821431', 'status' => 'in_wallet'],
            ],
            'delivery_notes' => false,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function commonSuppliers(string $vertical): array
    {
        $first = match ($vertical) {
            'butcher' => ['name' => 'Frigorífico Regional del Norte SA', 'contact' => 'Raúl Mendoza', 'email' => 'ventas@frigorificonorte.com.ar'],
            'hardware_store' => ['name' => 'Maderas & Chapas Sudamericana SRL', 'contact' => 'Esteban Quispe', 'email' => 'mayorista@sudamericanaferr.com.ar'],
            'apparel' => ['name' => 'Textil Mayorista Buenos Aires SRL', 'contact' => 'Florencia Peña', 'email' => 'pedidos@textilba.com.ar'],
            'tech' => ['name' => 'Importadora TechParts Argentina SA', 'contact' => 'Lucas Giménez', 'email' => 'gremio@techparts.com.ar'],
            'general' => ['name' => 'Distribuidora Papelera & Bazar del Plata', 'contact' => 'Carla Domínguez', 'email' => 'ventas@papeleradelplata.com.ar'],
            default => ['name' => 'Distribuidora Mayorista del Norte SA', 'contact' => 'Marcos Varela', 'email' => 'ventas@distribuidoranorte.com.ar'],
        };

        return [
            [
                'name' => $first['name'],
                'cuit' => '30714589233',
                'tax_category' => 'Responsable Inscripto',
                'contact_name' => $first['contact'],
                'phone' => '3704-589223',
                'email' => $first['email'],
                'address' => 'Av. Independencia 2450, Formosa',
            ],
            [
                'name' => 'Abastecedora Comercial Litoral SRL',
                'cuit' => '30689451248',
                'tax_category' => 'Responsable Inscripto',
                'contact_name' => 'Juan Carlos Rossi',
                'phone' => '11-5522-8899',
                'email' => 'pedidos@litoralcomercial.com.ar',
                'address' => 'Ruta Nac. 11 Km 1140',
            ],
            [
                'name' => 'Insumos y Embalajes San Martín',
                'cuit' => '30612345879',
                'tax_category' => 'Responsable Inscripto',
                'contact_name' => 'Silvia Navarro',
                'phone' => '3704-488220',
                'email' => 'administracion@insumossanmartin.com.ar',
                'address' => 'España 1245, Formosa',
            ],
            [
                'name' => 'Logística Express Mayorista',
                'cuit' => '30709823147',
                'tax_category' => 'Monotributo',
                'contact_name' => 'Roberto Gómez',
                'phone' => '3704-466331',
                'email' => 'logisticaexpress@gmail.com',
                'address' => 'San Martín 840, Formosa',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function commonCustomers(string $vertical): array
    {
        $vipName = match ($vertical) {
            'butcher' => 'Parrilla & Restó El Fogón SRL',
            'hardware_store' => 'Construcciones & Servicios del Norte SA',
            'apparel' => 'Club Atlético & Indumentaria SRL',
            'tech' => 'Estudio Contable & Sistemas Romero SRL',
            'general' => 'Colegio Privado San Martín',
            default => 'Supermercado Los Primos SRL',
        };

        return [
            [
                'name' => $vipName,
                'phone' => '3704-455889',
                'document_number' => '30712345671',
                'document_type' => 80,
                'tax_condition' => 'responsable_inscripto',
                'delivery_address' => 'Av. 25 de Mayo 1420',
                'fiscal_address' => 'Av. 25 de Mayo 1420, Formosa',
                'credit_limit' => 1500000.00,
                'default_price_tier' => 'wholesale',
                'is_internal_account' => false,
                'seed_debt' => 145000.00,
                'seed_payment' => 45000.00,
            ],
            [
                'name' => 'Martín Rodríguez (Comercio El Paso)',
                'phone' => '3704-466778',
                'document_number' => '20321148906',
                'document_type' => 80,
                'tax_condition' => 'monotributo',
                'delivery_address' => 'Rivadavia 650',
                'fiscal_address' => 'Rivadavia 650, Formosa',
                'credit_limit' => 450000.00,
                'default_price_tier' => 'base',
                'is_internal_account' => false,
                'seed_debt' => 68500.00,
                'seed_payment' => 20000.00,
            ],
            [
                'name' => 'Valentina Morales',
                'phone' => '3704-422334',
                'document_number' => '38452190',
                'document_type' => 96,
                'tax_condition' => 'consumidor_final',
                'delivery_address' => 'Barrio San Francisco Mza 12',
                'fiscal_address' => 'Barrio San Francisco Mza 12, Formosa',
                'credit_limit' => 180000.00,
                'default_price_tier' => 'base',
                'is_internal_account' => false,
                'seed_debt' => 24000.00,
                'seed_payment' => 0.00,
            ],
            [
                'name' => 'Mariana Belén Gómez',
                'phone' => '3704-499112',
                'document_number' => '40112983',
                'document_type' => 96,
                'tax_condition' => 'consumidor_final',
                'delivery_address' => 'Fontana 1230 Piso 2',
                'fiscal_address' => 'Fontana 1230 Piso 2, Formosa',
                'credit_limit' => 120000.00,
                'default_price_tier' => 'base',
                'is_internal_account' => false,
                'seed_debt' => 0.00,
                'seed_payment' => 0.00,
            ],
            [
                'name' => 'Consumo Interno del Local (Personal)',
                'phone' => '3704-400000',
                'document_number' => '30659821431',
                'document_type' => 80,
                'tax_condition' => 'consumidor_final',
                'delivery_address' => 'Sucursal Central',
                'fiscal_address' => 'Sucursal Central',
                'credit_limit' => 500000.00,
                'default_price_tier' => 'base',
                'is_internal_account' => true,
                'seed_debt' => 0.00,
                'seed_payment' => 0.00,
            ],
        ];
    }
}
