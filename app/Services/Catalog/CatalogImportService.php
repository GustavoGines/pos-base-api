<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Rubro;
use Illuminate\Support\Str;
use Rap2hpoutre\FastExcel\FastExcel;

class CatalogImportService
{
    /**
     * Import products from an Excel/CSV file path.
     */
    public function import(string $filePath): array
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(600);

        $createdCount = 0;
        $updatedCount = 0;
        $failedCount = 0;
        $errors = [];

        $defaultRubro = Rubro::where('is_system', true)->first();

        (new FastExcel)->import($filePath, function ($row) use (&$createdCount, &$updatedCount, &$failedCount, &$errors, $defaultRubro) {
            $rowLower = [];
            foreach ($row as $k => $v) {
                $key = strtolower(trim((string) $k));
                $key = str_replace(' ', '_', $key);
                $rowLower[$key] = $v;
            }

            $getVal = function ($keys) use ($rowLower) {
                foreach ($keys as $k) {
                    if (isset($rowLower[$k]) && $rowLower[$k] !== '') {
                        return trim((string) $rowLower[$k]);
                    }
                }

                return null;
            };

            $nombre = $getVal(['nombre', 'descripcion', 'descripción', 'articulo', 'artículo', 'producto', 'detalle']);
            $codInterno = $getVal(['codigo_interno', 'código_interno', 'codigo', 'cod', 'id']);
            $codBarras = $getVal(['codigo_barras', 'código_barras', 'barra', 'ean']);
            $costo = $getVal(['costo', 'precios_costos_g3', 'precio_costo', 'costo_unitario']);
            $precio = $getVal(['precio', 'precios_pesos_g3_nomas', 'precio_venta', 'precio_publico', 'venta']);
            $stock = $getVal(['stock', 'cantidad', 'cant', 'existencia']);
            $categoria = $getVal(['categoria', 'categoría', 'rubro', 'familia']);
            $marca = $getVal(['marca', 'laboratorio', 'fabricante']);
            $iva = $getVal(['iva', 'impuesto', 'tasa']);
            $activo = $getVal(['activo', 'estado']);

            if (! $nombre && ! $codInterno && ! $codBarras) {
                return null;
            }

            try {
                $internalCode = $codInterno ?? ($codBarras ?? 'PROD-'.Str::random(8));

                $product = null;
                if ($codInterno) {
                    $product = Product::where('internal_code', $codInterno)->first();
                }
                if (! $product && $codBarras) {
                    $product = Product::where('barcode', $codBarras)->first();
                }
                if (! $product && $nombre) {
                    $product = Product::where('name', $nombre)->first();
                }

                $isNew = false;
                if (! $product) {
                    if (! $nombre) {
                        $failedCount++;

                        return null;
                    }
                    $product = new Product;
                    $product->internal_code = $internalCode;
                    $isNew = true;
                }

                if ($categoria) {
                    $cat = Category::firstOrCreate(
                        ['name' => $categoria],
                        ['rubro_id' => $defaultRubro ? $defaultRubro->id : null]
                    );
                    $product->category_id = $cat->id;
                }

                if ($marca) {
                    $bnd = Brand::firstOrCreate(['name' => $marca]);
                    $product->brand_id = $bnd->id;
                }

                $parseNumber = function ($val) {
                    if ($val === null || $val === '') {
                        return null;
                    }
                    if (is_numeric($val)) {
                        return (float) $val;
                    }
                    $val = (string) $val;
                    // Remover todo excepto dígitos, coma, punto y signo menos
                    $val = preg_replace('/[^\d\,\.\-]/', '', $val);

                    $lastComma = strrpos($val, ',');
                    $lastDot = strrpos($val, '.');

                    if ($lastComma !== false && $lastDot !== false) {
                        if ($lastComma > $lastDot) {
                            // 1.234,56
                            $val = str_replace('.', '', $val);
                            $val = str_replace(',', '.', $val);
                        } else {
                            // 1,234.56
                            $val = str_replace(',', '', $val);
                        }
                    } elseif ($lastComma !== false) {
                        // Solo coma, asumimos decimal argentino: 1234,56
                        $val = str_replace(',', '.', $val);
                    }

                    return (float) $val;
                };

                if ($nombre) {
                    $product->name = $nombre;
                }
                if ($codBarras) {
                    $product->barcode = $codBarras;
                }
                if ($costo !== null) {
                    $product->cost_price = $parseNumber($costo);
                }
                if ($precio !== null) {
                    $product->selling_price = $parseNumber($precio);
                }
                if ($stock !== null) {
                    $product->stock = $parseNumber($stock);
                }
                if ($iva !== null) {
                    $product->iva_rate = $parseNumber($iva);
                }
                if ($activo !== null) {
                    $product->active = filter_var($activo, FILTER_VALIDATE_BOOLEAN);
                }

                $product->save();

                if ($isNew) {
                    $createdCount++;
                } else {
                    $updatedCount++;
                }
            } catch (\Throwable $e) {
                $failedCount++;
                $errors[] = $e->getMessage();
            }

            return null;
        });

        return [
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
            'failed_count' => $failedCount,
            'errors' => $errors,
        ];
    }
}
