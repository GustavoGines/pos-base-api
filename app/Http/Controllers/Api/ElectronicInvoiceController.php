<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ElectronicInvoice;
use App\Models\Sale;
use App\Services\Afip\AfipWsfeService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ElectronicInvoiceController extends Controller
{
    public function __construct(
        protected AfipWsfeService $wsfeService
    ) {}

    /**
     * Emite y solicita autorización fiscal (CAE) para una venta (Factura A, B o C).
     * POST /api/sales/{sale}/invoice
     */
    public function issueInvoice(Request $request, Sale $sale): JsonResponse
    {
        $validated = $request->validate([
            'voucher_type' => 'nullable|integer|in:1,6,11',
            'voucher_letter' => 'nullable|string|in:A,B,C,a,b,c',
            'doc_type' => 'nullable|integer',
            'doc_number' => 'nullable|string',
            'receiver_name' => 'nullable|string|max:150',
            'receiver_address' => 'nullable|string|max:255',
            'receiver_tax_condition' => 'nullable|string|max:50',
            'point_of_sale' => 'nullable|integer',
            'iibb_perception_amount' => 'nullable|numeric|min:0',
            'iibb_perception_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($sale, $validated) {
            $lockedSale = Sale::with('electronicInvoice')->lockForUpdate()->find($sale->id);
            
            if ($lockedSale->isVoided()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No es posible emitir factura electrónica para una venta anulada.',
                    'invoice_status' => 'failed',
                ], 422);
            }

            // Si ya fue autorizada, retornar la factura existente
            $existingInvoice = $lockedSale->electronicInvoice;
            if ($existingInvoice && $existingInvoice->isAuthorized()) {
                return response()->json([
                    'success' => true,
                    'message' => 'La venta ya posee una factura electrónica autorizada previamente.',
                    'invoice' => $existingInvoice,
                ]);
            }

            try {
                $invoiceData = $this->wsfeService->authorizeInvoice($lockedSale, $validated);

                $invoice = ElectronicInvoice::updateOrCreate(
                    ['sale_id' => $lockedSale->id],
                    array_merge($invoiceData, [
                        'issued_at' => now(),
                    ])
                );

                $lockedSale->update(['invoice_status' => 'invoiced']);

                return response()->json([
                    'success' => true,
                    'message' => 'Factura autorizada exitosamente',
                    'invoice' => $invoice,
                ]);
            } catch (Throwable $e) {
                $errorMessage = $e->getMessage();
                Log::error("Error al autorizar factura electrónica para venta #{$lockedSale->id}: {$errorMessage}", [
                    'exception' => $e,
                ]);

                // Determinar si es una falla de conexión / timeout de AFIP (Contingencia)
                $isTimeout = str_contains(strtolower($errorMessage), 'timeout')
                    || str_contains(strtolower($errorMessage), 'timed out')
                    || str_contains(strtolower($errorMessage), 'connection')
                    || str_contains(strtolower($errorMessage), 'curl error 28')
                    || str_contains(strtolower($errorMessage), '504')
                    || str_contains(strtolower($errorMessage), 'no disponible');

                if ($isTimeout) {
                    $lockedSale->update(['invoice_status' => 'pending']);

                    return response()->json([
                        'success' => false,
                        'message' => 'Servicio de AFIP no disponible. Venta registrada en contingencia.',
                        'invoice_status' => 'pending',
                        'error' => $errorMessage,
                    ], 504);
                }

                // Rechazo de AFIP o validación impositiva
                $lockedSale->update(['invoice_status' => 'failed']);

                return response()->json([
                    'success' => false,
                    'message' => "La solicitud fiscal no pudo ser autorizada: {$errorMessage}",
                    'invoice_status' => 'failed',
                    'error' => $errorMessage,
                ], 422);
            }
        });
    }

    /**
     * Consulta los datos fiscales y código QR de una factura electrónica existente.
     * GET /api/sales/{sale}/electronic-invoice
     */
    public function show(Sale $sale): JsonResponse
    {
        $invoice = $sale->electronicInvoice;

        if (! $invoice) {
            return response()->json([
                'success' => false,
                'message' => 'La venta no posee factura electrónica asociada.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'invoice' => $invoice,
        ]);
    }
}
