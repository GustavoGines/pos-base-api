<?php

namespace App\Services\Catalog;

use App\Exports\PriceListExport;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * Stream CSV import template with BOM for Excel compatibility.
     */
    public function downloadTemplate(): StreamedResponse
    {
        $headers = ['codigo_interno', 'codigo_barras', 'nombre', 'costo', 'precio', 'stock', 'categoria', 'marca', 'iva', 'activo'];
        $callback = function () use ($headers) {
            $file = fopen('php://output', 'w');
            // Byte Order Mark (BOM) UTF-8 para apertura correcta en Excel
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($file, $headers, ';');
            fputcsv($file, ['PROD-001', '7791234567890', 'Ejemplo Arroz', '100.00', '150.00', '10', 'Almacen', 'Marolio', '21.00', 'true'], ';');
            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="plantilla_productos.csv"',
        ]);
    }
}
