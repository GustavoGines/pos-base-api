<?php

namespace App\Exports;

use App\Models\BusinessSetting;
use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PriceListExport extends DefaultValueBinder implements FromQuery, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithHeadings, WithMapping, WithStyles
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
            ->with(['category.rubro', 'brand', 'supplier', 'children'])
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
        $base = [
            'codigo_interno',
            'codigo_barras',
            'nombre',
            'categoria',
            'marca',
            'proveedor',
            'costo',
            'precio',
            'stock',
            'stock_minimo',
            'unidad',
            'vencimiento_dias',
            'activo',
        ];

        if (Product::hasExtendedCatalogColumns()) {
            return array_merge($base, [
                'cod_proveedor',
                'medida',
                'ubicacion',
                'presentacion_compra',
                'factor_conversion',
            ]);
        }

        return $base;
    }

    public function map($product): array
    {
        $cost = $product->is_combo
            ? $product->calculateComboRealCost()
            : (float) $product->cost_price;

        $stock = $product->is_combo
            ? $product->calculateComboAvailableStock()
            : (float) $product->stock;

        $rawUnit = strtolower(trim((string) ($product->unit_type ?? '')));
        if ($rawUnit !== '' && $rawUnit !== 'un') {
            $unit = $rawUnit;
        } elseif (! $product->is_sold_by_weight) {
            $unit = 'un';
        } else {
            $hasCalcConfig = is_array($product->calc_config) && count($product->calc_config) > 0;
            $rubro = $product->category?->rubro;
            $rubroVertical = strtolower(trim((string) ($rubro->vertical_type ?? '')));
            $rubroName = strtolower(trim((string) ($rubro->name ?? '')));
            $isHardwareRubro = $rubroVertical === 'hardware_store'
                || (bool) preg_match('/ferret|mader|corral/', $rubroName);
            $isScaleRubro = in_array($rubroVertical, ['retail', 'butcher'], true)
                || (bool) preg_match('/verdul|carnic|fiambr|poller|supermerc|kiosc|almac|minimerc|panad/', $rubroName);

            $primaryVertical = strtolower(trim((string) (
                BusinessSetting::where('key', 'license_business_type')->value('value')
                ?? BusinessSetting::where('key', 'business_type')->value('value')
                ?? 'retail'
            )));
            $secondaryVerticals = BusinessSetting::getSecondaryBusinessTypes();
            $storeIsPureNonScaleHardware = BusinessSetting::hasVerticalCapability('hardware_store')
                && ! BusinessSetting::hasNativeBatchExpiration($primaryVertical, $secondaryVerticals);

            $unit = ($hasCalcConfig || $isHardwareRubro || ($storeIsPureNonScaleHardware && ! $isScaleRubro))
                ? 'un'
                : 'kg';
        }

        $row = [
            (string) ($product->internal_code ?? ''),
            (string) ($product->barcode ?? ''),
            $product->name,
            $product->category ? $product->category->name : '',
            $product->brand ? $product->brand->name : '',
            $product->supplier ? $product->supplier->name : '',
            $cost,
            (float) $product->selling_price,
            $stock,
            $product->min_stock !== null ? (float) $product->min_stock : '',
            $unit,
            $product->vencimiento_dias !== null ? (int) $product->vencimiento_dias : '',
            $product->active ? 'SI' : 'NO',
        ];

        if (Product::hasExtendedCatalogColumns()) {
            $row[] = (string) ($product->supplier_sku ?? '');
            $row[] = (string) ($product->technical_spec ?? '');
            $row[] = (string) ($product->storage_location ?? '');
            $row[] = (string) ($product->purchase_package_name ?? '');
            $row[] = $product->conversion_factor !== null ? (float) $product->conversion_factor : '';
        }

        return $row;
    }

    public function bindValue(Cell $cell, $value)
    {
        if (in_array($cell->getColumn(), ['A', 'B', 'N'], true) && $cell->getRow() > 1 && $value !== null && $value !== '') {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function columnFormats(): array
    {
        $formats = [
            'A' => NumberFormat::FORMAT_TEXT,
            'B' => NumberFormat::FORMAT_TEXT,
            'G' => NumberFormat::FORMAT_NUMBER_00,
            'H' => NumberFormat::FORMAT_NUMBER_00,
            'I' => NumberFormat::FORMAT_NUMBER_00,
            'J' => NumberFormat::FORMAT_NUMBER_00,
        ];

        if (Product::hasExtendedCatalogColumns()) {
            $formats['N'] = NumberFormat::FORMAT_TEXT;
            $formats['R'] = NumberFormat::FORMAT_NUMBER_00;
        }

        return $formats;
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
