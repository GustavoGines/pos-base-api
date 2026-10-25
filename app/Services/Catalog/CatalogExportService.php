<?php

namespace App\Services\Catalog;

use App\Exports\CatalogImportTemplateExport;
use App\Exports\PriceListExport;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CatalogExportService
{
    /**
     * Download price list Excel file.
     */
    public function exportPriceList(?int $categoryId = null, ?int $brandId = null): BinaryFileResponse
    {
        return Excel::download(
            new PriceListExport($categoryId, $brandId),
            'lista_precios.xlsx'
        );
    }

    /**
     * Download real .xlsx import template compatible with Microsoft Excel and FastExcel.
     */
    public function downloadTemplate(): BinaryFileResponse
    {
        return Excel::download(
            new CatalogImportTemplateExport,
            'plantilla_importacion_productos.xlsx'
        );
    }
}
