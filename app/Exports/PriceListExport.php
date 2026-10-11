<?php

namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PriceListExport implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithStyles
{
    protected $categoryId;

    protected $brandId;

    public function __construct($categoryId = null, $brandId = null)
    {
        $this->categoryId = $categoryId;
        $this->brandId = $brandId;
    }

    public function query()
    {
        $query = Product::query()
            ->with(['category', 'brand', 'supplier'])
            ->where('active', true)
            ->orderBy('name');

        if ($this->categoryId) {
            $query->where('category_id', $this->categoryId);
        }

        if ($this->brandId) {
            $query->where('brand_id', $this->brandId);
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'Código Interno', 'Código Barras', 'Producto', 'Categoría',
            'Marca', 'Proveedor', 'Stock', 'IVA (%)', 'Costo',
            'Precio Mostrador', 'Precio Tarjeta', 'Precio Mayorista',
        ];
    }

    public function map($product): array
    {
        return [
            $product->internal_code,
            $product->barcode,
            $product->name,
            $product->category ? $product->category->name : '',
            $product->brand ? $product->brand->name : '',
            $product->supplier ? $product->supplier->name : '',
            (float) $product->stock,
            (float) $product->iva_rate,
            (float) $product->cost_price,
            (float) $product->selling_price,
            (float) $product->price_card,
            (float) $product->price_wholesale,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'G' => NumberFormat::FORMAT_NUMBER_00, // Stock
            'H' => NumberFormat::FORMAT_NUMBER_00, // IVA
            'I' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE, // Costo
            'J' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE, // Precio Mostrador
            'K' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE, // Precio Tarjeta
            'L' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE, // Precio Mayorista
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => Color::COLOR_BLACK]],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFE0E0E0'],
                ],
            ],
        ];
    }
}
