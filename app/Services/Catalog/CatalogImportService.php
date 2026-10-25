<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Rubro;
use App\Models\Supplier;
use App\Services\BarcodeService;
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
        $barcodeService = app(BarcodeService::class);

        (new FastExcel)->import($filePath, function ($row) use (&$createdCount, &$updatedCount, &$failedCount, &$errors, $defaultRubro, $barcodeService) {
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
            $precio = $getVal(['precio', 'precio_mostrador', 'precios_pesos_g3_nomas', 'precio_venta', 'precio_publico', 'venta']);
            $precioTarjeta = $getVal(['precio_tarjeta']);
            $precioMayorista = $getVal(['precio_mayorista']);
            $stock = $getVal(['stock', 'cantidad', 'cant', 'existencia']);
            $stockMinimo = $getVal(['stock_minimo', 'stock_mínimo', 'min_stock', 'alerta_stock']);
            $unidad = $getVal(['unidad', 'unit_type', 'tipo_unidad', 'balanza']);
            $vencimientoDias = $getVal(['vencimiento_dias', 'vencimiento_días', 'dias_vencimiento', 'vto']);
            $categoria = $getVal(['categoria', 'categoría', 'rubro', 'familia']);
            $marca = $getVal(['marca', 'laboratorio', 'fabricante']);
            $proveedor = $getVal(['proveedor', 'supplier']);
            $iva = $getVal(['iva', 'iva_(%)', 'impuesto', 'tasa']);
            $activo = $getVal(['activo', 'estado']);

            if (! $nombre && ! $codInterno && ! $codBarras) {
                return null;
            }

            try {
                // Normalizar código interno numérico al estándar de 5 dígitos (ej. "1" -> "00001")
                if ($codInterno !== null && ctype_digit($codInterno)) {
                    $codInterno = str_pad($codInterno, 5, '0', STR_PAD_LEFT);
                }

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
                    $product->internal_code = $codInterno ?: $barcodeService->generateUniqueInternalCode();
                    $isNew = true;
                } elseif ($codInterno) {
                    $product->internal_code = $codInterno;
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

                if ($proveedor) {
                    $sup = Supplier::firstOrCreate(['name' => $proveedor]);
                    $product->supplier_id = $sup->id;
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

                $oldStock = $isNew ? 0.0 : (float) $product->stock;

                if ($nombre) {
                    $product->name = $nombre;
                }

                // Unidad y Balanza (un, kg, lt, g)
                if ($unidad !== null) {
                    $uNorm = strtolower(trim($unidad));
                    if (in_array($uNorm, ['kg', 'kilo', 'kilogramo', 'pesable', 'balanza', 'si', 'sí'], true)) {
                        $product->unit_type = 'kg';
                        $product->is_sold_by_weight = true;
                    } elseif (in_array($uNorm, ['lt', 'litro', 'g', 'gr', 'gramo'], true)) {
                        $product->unit_type = in_array($uNorm, ['lt', 'litro'], true) ? 'lt' : 'g';
                        $product->is_sold_by_weight = false;
                    } else {
                        $product->unit_type = 'un';
                        $product->is_sold_by_weight = false;
                    }
                } elseif ($isNew) {
                    $product->unit_type = 'un';
                    $product->is_sold_by_weight = false;
                }

                // Código de barras: si viene en el Excel lo usamos; si es producto nuevo por unidad y está vacío, generamos EAN-13 interno
                if ($codBarras) {
                    $product->barcode = $codBarras;
                } elseif ($isNew && empty($product->barcode)) {
                    $product->barcode = empty($product->is_sold_by_weight)
                        ? $barcodeService->generateInternalEan13($product->internal_code)
                        : null;
                }

                if ($costo !== null) {
                    $product->cost_price = $parseNumber($costo) ?? 0.0;
                } elseif ($isNew) {
                    $product->cost_price = 0.0;
                }

                if ($precio !== null) {
                    $product->selling_price = $parseNumber($precio) ?? 0.0;
                } elseif ($isNew) {
                    $product->selling_price = 0.0;
                }

                if ($precioTarjeta !== null) {
                    $product->price_card = $parseNumber($precioTarjeta);
                }
                if ($precioMayorista !== null) {
                    $product->price_wholesale = $parseNumber($precioMayorista);
                }
                if ($stock !== null && ! $product->is_combo) {
                    $product->stock = $parseNumber($stock) ?? 0.0;
                } elseif ($isNew && $stock === null) {
                    $product->stock = 0.0;
                }

                if ($stockMinimo !== null) {
                    $product->min_stock = $parseNumber($stockMinimo);
                }

                if ($vencimientoDias !== null) {
                    $parsedVto = (int) round((float) ($parseNumber($vencimientoDias) ?? 0));
                    $product->vencimiento_dias = ($parsedVto >= 1 && $parsedVto <= 3650) ? $parsedVto : null;
                }

                if ($iva !== null) {
                    $product->iva_rate = $parseNumber($iva);
                }

                if ($activo !== null) {
                    $actNorm = strtolower(trim($activo));
                    if (in_array($actNorm, ['si', 'sí', 'true', '1', 'activo', 'yes', 's'], true)) {
                        $product->active = true;
                    } elseif (in_array($actNorm, ['no', 'false', '0', 'inactivo', 'n'], true)) {
                        $product->active = false;
                    } else {
                        $product->active = filter_var($activo, FILTER_VALIDATE_BOOLEAN);
                    }
                } elseif ($isNew) {
                    $product->active = true;
                }

                $product->save();

                if ($stock !== null && ! $product->is_combo) {
                    $newStock = (float) $product->stock;
                    $diff = round($newStock - $oldStock, 4);
                    if ($diff != 0.0) {
                        $actorUser = request()?->user() ?? request()?->attributes?->get('authenticated_user');
                        $product->stockMovements()->create([
                            'user_id' => $actorUser?->id ?? auth()->id(),
                            'type' => $diff > 0 ? 'in' : 'out',
                            'quantity' => abs($diff),
                            'notes' => $isNew
                                ? 'Stock inicial (Importación de catálogo)'
                                : "Actualización por importación de catálogo (de {$oldStock} a {$newStock})",
                        ]);
                    }
                }

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
