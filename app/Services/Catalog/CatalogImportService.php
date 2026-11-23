<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Rubro;
use App\Models\Supplier;
use App\Services\BarcodeService;
use App\Services\BatchStockService;
use Illuminate\Support\Facades\DB;
use Rap2hpoutre\FastExcel\FastExcel;

class CatalogImportService
{
    /**
     * Mapa uniforme de alias de unidad => [unit_type canonico, is_sold_by_weight].
     */
    private const UNIT_ALIASES = [
        'kg' => ['kg', true],
        'kilo' => ['kg', true],
        'kilogramo' => ['kg', true],
        'kilogramos' => ['kg', true],
        'pesable' => ['kg', true],
        'balanza' => ['kg', true],
        'si' => ['kg', true],
        'sí' => ['kg', true],
        'g' => ['g', true],
        'gr' => ['g', true],
        'gramo' => ['g', true],
        'gramos' => ['g', true],
        'lt' => ['lt', true],
        'l' => ['lt', true],
        'litro' => ['lt', true],
        'litros' => ['lt', true],
        'mt' => ['mt', true],
        'm' => ['mt', true],
        'ml' => ['mt', true],
        'metro' => ['mt', true],
        'metros' => ['mt', true],
        'metro_lineal' => ['mt', true],
        'm2' => ['m2', true],
        'm²' => ['m2', true],
        'metro_cuadrado' => ['m2', true],
        'metros_cuadrados' => ['m2', true],
        'm3' => ['m3', true],
        'm³' => ['m3', true],
        'metro_cubico' => ['m3', true],
        'metro_cúbico' => ['m3', true],
        'metros_cubicos' => ['m3', true],
        'bolsa' => ['bolsa', false],
        'bolsas' => ['bolsa', false],
        'rollo' => ['rollo', false],
        'rollos' => ['rollo', false],
        'caja' => ['caja', false],
        'cajas' => ['caja', false],
        'par' => ['par', false],
        'pares' => ['par', false],
        'un' => ['un', false],
        'u' => ['un', false],
        'unidad' => ['un', false],
        'unidades' => ['un', false],
    ];

    /**
     * @return array{0: string, 1: bool}
     */
    private function resolveUnitType(string $rawUnit): array
    {
        $uNorm = strtolower(str_replace(' ', '_', trim($rawUnit)));

        return self::UNIT_ALIASES[$uNorm] ?? ['un', false];
    }

    /**
     * Import products from an Excel/CSV file path.
     *
     * @param  array<string, mixed>  $options
     */
    public function import(string $filePath, array $options = []): array
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(600);

        $createdCount = 0;
        $updatedCount = 0;
        $failedCount = 0;
        $errors = [];

        $defaultRubro = Rubro::where('is_system', true)->first();
        $barcodeService = app(BarcodeService::class);
        $hasExtendedCatalogCols = Product::hasExtendedCatalogColumns();

        $rawDefaultSupplierId = $options['default_supplier_id'] ?? null;
        $validDefaultSupplierId = ($rawDefaultSupplierId !== null && $rawDefaultSupplierId !== '')
            ? Supplier::where('id', (int) $rawDefaultSupplierId)->value('id')
            : null;

        $rawDefaultMargin = $options['default_margin_percent'] ?? null;
        $defaultMarginPercent = ($rawDefaultMargin !== null && $rawDefaultMargin !== '' && is_numeric($rawDefaultMargin))
            ? max(0.0, (float) $rawDefaultMargin)
            : null;

        (new FastExcel)->import($filePath, function ($row) use (
            &$createdCount,
            &$updatedCount,
            &$failedCount,
            &$errors,
            $defaultRubro,
            $barcodeService,
            $hasExtendedCatalogCols,
            $validDefaultSupplierId,
            $defaultMarginPercent
        ) {
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
            $codProveedor = $getVal([
                'cod_proveedor',
                'codigo_proveedor',
                'código_proveedor',
                'cod._proveedor',
                'cod_prov',
                'cod._prov.',
                'supplier_sku',
                'sku_proveedor',
                'cod_art_proveedor',
            ]);
            $medida = $getVal([
                'medida',
                'especificacion',
                'especificación',
                'technical_spec',
                'medida_especificacion',
                'medida_/_especificacion',
                'medida_/_especificación',
            ]);
            $ubicacion = $getVal([
                'ubicacion',
                'ubicación',
                'estanteria',
                'estantería',
                'gondola',
                'góndola',
                'storage_location',
            ]);
            $presentacionCompra = $getVal([
                'presentacion_compra',
                'presentación_compra',
                'bulto_compra',
                'purchase_package_name',
                'presentacion',
            ]);
            $factorConversion = $getVal([
                'factor_conversion',
                'factor_conversión',
                'factor_de_conversion',
                'equivalencia',
                'conversion_factor',
            ]);
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

            if (! $nombre && ! $codInterno && ! $codBarras && ! $codProveedor) {
                return null;
            }

            try {
                // Normalizar código interno numérico al estándar de 5 dígitos (ej. "1" -> "00001")
                if ($codInterno !== null && ctype_digit($codInterno)) {
                    $codInterno = str_pad($codInterno, 5, '0', STR_PAD_LEFT);
                }

                $effectiveSupplierId = $validDefaultSupplierId;
                if ($proveedor) {
                    $sup = Supplier::firstOrCreate(['name' => $proveedor]);
                    $effectiveSupplierId = $sup->id;
                }

                $product = null;
                if ($codInterno) {
                    $product = Product::where('internal_code', $codInterno)->first();
                }
                if (! $product && $codBarras) {
                    $product = Product::where('barcode', $codBarras)->first();
                }
                if (! $product && $codProveedor && $hasExtendedCatalogCols) {
                    if ($effectiveSupplierId !== null) {
                        $product = Product::where('supplier_sku', $codProveedor)
                            ->where('supplier_id', $effectiveSupplierId)
                            ->first();
                    }
                    if (! $product) {
                        $skuMatches = Product::where('supplier_sku', $codProveedor)
                            ->when($effectiveSupplierId !== null, function ($q) use ($effectiveSupplierId) {
                                $q->where(function ($inner) use ($effectiveSupplierId) {
                                    $inner->whereNull('supplier_id')
                                        ->orWhere('supplier_id', $effectiveSupplierId);
                                });
                            })
                            ->limit(2)
                            ->get();
                        if ($skuMatches->count() === 1) {
                            $product = $skuMatches->first();
                        }
                    }
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

                if ($effectiveSupplierId !== null) {
                    $product->supplier_id = $effectiveSupplierId;
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

                // Unidad y Venta fraccionada / balanza (un, kg, lt, g, mt, m2, m3, bolsa, rollo, caja, par)
                if ($unidad !== null) {
                    [$resolvedUnit, $resolvedFractional] = $this->resolveUnitType($unidad);
                    $product->unit_type = $resolvedUnit;
                    $product->is_sold_by_weight = $resolvedFractional;
                } elseif ($isNew) {
                    $product->unit_type = 'un';
                    $product->is_sold_by_weight = false;
                }

                if ($hasExtendedCatalogCols) {
                    if ($codProveedor !== null) {
                        $product->supplier_sku = mb_substr($codProveedor, 0, 64);
                    }
                    if ($medida !== null) {
                        $product->technical_spec = mb_substr($medida, 0, 120);
                    }
                    if ($ubicacion !== null) {
                        $product->storage_location = mb_substr($ubicacion, 0, 120);
                    }
                    if ($presentacionCompra !== null) {
                        $product->purchase_package_name = mb_substr($presentacionCompra, 0, 40);
                    }
                    if ($factorConversion !== null) {
                        $parsedFactor = $parseNumber($factorConversion);
                        if ($parsedFactor !== null && $parsedFactor > 0) {
                            $product->conversion_factor = round($parsedFactor, 3);
                        }
                    }
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

                $parsedPrecio = $precio !== null ? $parseNumber($precio) : null;
                if ($parsedPrecio !== null && $parsedPrecio > 0) {
                    $product->selling_price = $parsedPrecio;
                } elseif ($defaultMarginPercent !== null && (float) $product->cost_price > 0) {
                    $product->selling_price = round(((float) $product->cost_price) * (1 + ($defaultMarginPercent / 100.0)), 2);
                } elseif ($parsedPrecio !== null) {
                    $product->selling_price = $parsedPrecio;
                } elseif ($isNew) {
                    $product->selling_price = 0.0;
                }

                if ($precioTarjeta !== null) {
                    $product->price_card = $parseNumber($precioTarjeta);
                }
                if ($precioMayorista !== null) {
                    $product->price_wholesale = $parseNumber($precioMayorista);
                }
                $parsedStockFromSheet = ($stock !== null && ! $product->is_combo)
                    ? ($parseNumber($stock) ?? 0.0)
                    : null;

                $hasLotSchema = BatchStockService::hasLotTrackingSchema();
                $isBatchTracked = $hasLotSchema && (bool) $product->track_batches;

                if ($parsedStockFromSheet !== null && ! $isBatchTracked) {
                    $product->stock = $parsedStockFromSheet;
                } elseif ($isNew && $parsedStockFromSheet === null) {
                    $product->stock = 0.0;
                } elseif ($isNew && $isBatchTracked) {
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

                if ($parsedStockFromSheet !== null && ! $product->is_combo) {
                    $diff = round($parsedStockFromSheet - $oldStock, 4);
                    if ($diff != 0.0) {
                        $actorUser = request()?->user() ?? request()?->attributes?->get('authenticated_user');
                        $noteText = $isNew
                            ? 'Stock inicial (Importación de catálogo)'
                            : "Actualización por importación de catálogo (de {$oldStock} a {$parsedStockFromSheet})";

                        if ($hasLotSchema && $product->track_batches && ! $product->is_service) {
                            DB::transaction(function () use ($product, $diff, $actorUser, $noteText) {
                                $locked = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();
                                $batchService = app(BatchStockService::class);
                                $batchService->ensureInitialBatchOnEnable($locked, $actorUser?->id ?? auth()->id());

                                if ($diff > 0) {
                                    $batchService->receiveStockBatch($locked, $diff, [
                                        'source_type' => ProductBatch::SOURCE_ADJUSTMENT,
                                        'unit_cost' => (float) $locked->cost_price,
                                        'user_id' => $actorUser?->id ?? auth()->id(),
                                        'movement_type' => 'in',
                                        'movement_notes' => $noteText,
                                    ]);
                                } else {
                                    $batchService->deductStockFEFO($locked, abs($diff), [
                                        'user_id' => $actorUser?->id ?? auth()->id(),
                                        'allow_deficit' => true,
                                        'ignore_expired_guard' => true,
                                        'movement_type' => 'out',
                                        'movement_notes' => $noteText,
                                    ]);
                                }
                            });
                        } else {
                            $product->stockMovements()->create([
                                'user_id' => $actorUser?->id ?? auth()->id(),
                                'type' => $diff > 0 ? 'in' : 'out',
                                'quantity' => abs($diff),
                                'unit_cost' => $product->cost_price,
                                'notes' => $noteText,
                            ]);
                        }
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
