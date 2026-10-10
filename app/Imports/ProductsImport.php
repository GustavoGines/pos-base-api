<?php

namespace App\Imports;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Rubro;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Facades\Log;

class ProductsImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    public int $createdCount = 0;
    public int $updatedCount = 0;
    public int $failedCount = 0;
    public array $errors = [];

    public function chunkSize(): int
    {
        return 500;
    }

    /**
    * @param Collection $rows
    */
    public function collection(Collection $rows)
    {
        $defaultRubro = Rubro::where('is_system', true)->first();

        foreach ($rows as $index => $row) {
            // Helper para buscar múltiples posibles nombres de columna
            $getVal = function($keys) use ($row) {
                foreach ($keys as $k) {
                    if (isset($row[$k]) && $row[$k] !== '') {
                        return trim($row[$k]);
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

            // Ignorar filas totalmente vacías
            if (!$nombre && !$codInterno && !$codBarras) {
                continue;
            }

            try {
                // Generar o usar código interno
                $internalCode = $codInterno ?? ($codBarras ?? 'PROD-' . Str::random(8));

                $product = null;

                // 1. Buscar por código interno
                if ($codInterno) {
                    $product = Product::where('internal_code', $codInterno)->first();
                }

                // 2. Si no, buscar por código de barras
                if (!$product && $codBarras) {
                    $product = Product::where('barcode', $codBarras)->first();
                }

                // 3. Fallback: Buscar por nombre exacto
                if (!$product && $nombre) {
                    $product = Product::where('name', $nombre)->first();
                }

                $isNew = false;
                if (!$product) {
                    // Es un producto nuevo, validar campos mínimos
                    if (!$nombre) {
                        $this->failedCount++;
                        $this->errors[] = "Fila " . ($index + 2) . ": Faltan campos obligatorios (nombre).";
                        continue;
                    }
                    $product = new Product();
                    $product->internal_code = $internalCode;
                    $isNew = true;
                }

                // Asignar Categoría dinámicamente
                if ($categoria) {
                    $cat = Category::firstOrCreate(
                        ['name' => $categoria],
                        ['rubro_id' => $defaultRubro ? $defaultRubro->id : null]
                    );
                    $product->category_id = $cat->id;
                }

                // Asignar Marca dinámicamente
                if ($marca) {
                    $bnd = Brand::firstOrCreate(['name' => $marca]);
                    $product->brand_id = $bnd->id;
                }

                // Mapear el resto de los campos
                if ($nombre) {
                    $product->name = $nombre;
                }
                if ($codBarras) {
                    $product->barcode = $codBarras;
                }
                if ($costo !== null && is_numeric(str_replace(',', '.', $costo))) {
                    $product->cost_price = (float) str_replace(',', '.', $costo);
                }
                if ($precio !== null && is_numeric(str_replace(',', '.', $precio))) {
                    $product->selling_price = (float) str_replace(',', '.', $precio);
                }
                if ($stock !== null && is_numeric(str_replace(',', '.', $stock))) {
                    $product->stock = (float) str_replace(',', '.', $stock);
                }
                if ($iva !== null && is_numeric(str_replace(',', '.', $iva))) {
                    $product->iva_rate = (float) str_replace(',', '.', $iva);
                }
                if ($activo !== null) {
                    $product->active = filter_var($activo, FILTER_VALIDATE_BOOLEAN);
                }

                $product->save();

                if ($isNew) {
                    $this->createdCount++;
                } else {
                    $this->updatedCount++;
                }

            } catch (\Exception $e) {
                Log::error("Error importando fila " . ($index + 2) . ": " . $e->getMessage());
                $this->failedCount++;
                $this->errors[] = "Fila " . ($index + 2) . ": Error al guardar (" . $e->getMessage() . ").";
            }
        }
    }
}
