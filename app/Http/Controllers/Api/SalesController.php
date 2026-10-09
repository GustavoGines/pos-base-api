<?php

namespace App\Http\Controllers\Api;

use App\DTOs\PaySaleDTO;
use App\DTOs\SaleContextDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaySaleRequest;
use App\Http\Requests\VoidSaleRequest;
use App\Models\BusinessSetting;
use App\Models\Sale;
use App\Services\SaleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class SalesController extends Controller
{
    /**
     * GET /api/sales
     * Devuelve las ventas del turno de caja activo (o las del día si se especifica un shift_id).
     */
    public function index(Request $request)
    {
        $shiftId = $request->query('shift_id');
        $period = $request->query('period', 'shift');
        $userId = $request->query('user_id');

        $query = Sale::with([
            'items.product:id,name,internal_code,stock,is_sold_by_weight',
            'user:id,name',
            'cashier:id,name',
            'payments.paymentMethod:id,name,code,is_cash',
            'electronicInvoice',
        ])
            ->where('status', '!=', 'pending')
            ->latest();

        if ($period === 'today') {
            $query->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()]);
        } elseif ($period === 'yesterday') {
            $query->whereBetween('created_at', [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()]);
        } elseif ($period === 'week') {
            $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]);
        } elseif ($period === 'month') {
            $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]);
        } elseif ($period === 'year') {
            $query->whereBetween('created_at', [now()->startOfYear(), now()->endOfYear()]);
        } elseif ($period === 'all') {
            // Sin filtro de fecha
        } else {
            // Comportamiento por defecto ('shift')
            if ($shiftId) {
                $query->where('cash_shift_id', $shiftId);
            } else {
                // En un entorno Multi-Caja, si no envían shiftId, significa que la terminal
                // actual no tiene un turno abierto. No debemos adivinar usando el último turno global.
                return response()->json([]);
            }
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return response()->json($query->get());
    }

    /**
     * GET /api/sales/{sale}
     * Devuelve una venta específica con todos sus ítems, pagos y cliente.
     */
    public function show(Sale $sale)
    {
        $sale->load([
            'items.product:id,name,internal_code,is_sold_by_weight',
            'user:id,name',
            'cashier:id,name',
            'customer:id,name,document_number',
            'payments.paymentMethod:id,name,code,is_cash',
            'electronicInvoice',
        ]);

        return response()->json($sale);
    }

    /**
     * GET /api/sales/pending
     * Devuelve todos los tickets en espera (status = 'pending').
     */
    public function pending()
    {
        $sales = Sale::with([
            'items.product:id,name,internal_code,stock,is_sold_by_weight',
            'user:id,name',
            'cashier:id,name',
            'payments.paymentMethod:id,name,code,is_cash',
        ])
            ->where('status', 'pending')
            ->latest()
            ->get();

        return response()->json($sales);
    }

    /**
     * PUT /api/sales/{sale}/pay
     */
    public function pay(PaySaleRequest $request, Sale $sale, SaleService $saleService)
    {
        $validated = $request->validated();

        $dto = PaySaleDTO::fromArray($validated);
        $context = SaleContextDTO::fromArray(
            $validated,
            $request->user()?->id ?? $request->attributes->get('authenticated_user')?->id
        );

        try {
            $completedSale = $saleService->payPendingSale($sale, $dto, $context);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => true, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Venta #{$completedSale->id} cobrada correctamente.",
            'sale' => $completedSale->fresh()->load('items.product', 'user:id,name', 'cashier:id,name', 'payments.paymentMethod:id,name,code,is_cash'),
        ]);
    }

    /**
     * POST /api/sales/{sale}/void
     */
    public function void(VoidSaleRequest $request, Sale $sale, SaleService $saleService)
    {
        $validated = $request->validated();

        $actingUser = $request->user() ?? $request->attributes->get('authenticated_user');
        $authorizedByAdminId = $request->attributes->get('authorized_by_admin_id')
            ?? ($actingUser?->isAdmin() ? $actingUser->id : null);

        $context = SaleContextDTO::fromArray(
            $validated,
            $actingUser?->id,
            false,
            $authorizedByAdminId
        );

        try {
            $voidedSale = $saleService->voidSale($sale, $context);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => true, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Venta #{$voidedSale->id} anulada correctamente. El stock fue restaurado.",
            'sale' => $voidedSale->fresh()->load('items.product', 'user:id,name', 'cashier:id,name', 'payments.paymentMethod:id,name,code,is_cash'),
        ]);
    }

    /**
     * GET /api/sales/{sale}/ticket-pdf
     * Descarga el PDF del comprobante/factura en formato A4
     */
    public function ticketPdf(Sale $sale)
    {
        $sale->load('items.product', 'user', 'cashier', 'customer', 'payments.paymentMethod');
        $settings = BusinessSetting::all()->pluck('value', 'key')->toArray();

        $pdf = Pdf::loadView('pdf.ticket_a4', [
            'sale' => $sale,
            'settings' => $settings,
        ]);

        return response($pdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="ticket-'.$sale->id.'.pdf"');
    }
}
