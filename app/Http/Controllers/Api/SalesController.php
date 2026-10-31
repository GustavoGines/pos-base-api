<?php

namespace App\Http\Controllers\Api;

use App\DTOs\PaySaleDTO;
use App\DTOs\SaleContextDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaySaleRequest;
use App\Http\Requests\VoidSaleRequest;
use App\Models\BusinessSetting;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\Sale;
use App\Services\DiscountAuthorizationService;
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
        $cashRegisterId = $request->query('cash_register_id');
        $period = $request->query('period', 'shift');
        $userId = $request->query('user_id');

        $query = Sale::with([
            'items.product:id,name,internal_code,stock,is_sold_by_weight,selling_price',
            'items.product.offers',
            'user:id,name',
            'cashier:id,name',
            'customer:id,name,document_type,document_number,tax_condition,fiscal_address,delivery_address',
            'cashShift.cashRegister:id,name',
            'payments.paymentMethod:id,name,code,is_cash',
            'electronicInvoice',
            'deliveryNote.items.product:id,name,internal_code,is_sold_by_weight',
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
            } elseif (! empty($cashRegisterId) && is_numeric($cashRegisterId)) {
                $openShiftForRegister = CashShift::where('cash_register_id', (int) $cashRegisterId)
                    ->where('status', 'open')
                    ->latest('id')
                    ->first();
                if ($openShiftForRegister) {
                    $query->where('cash_shift_id', $openShiftForRegister->id);
                } else {
                    return response()->json([]);
                }
            } elseif (CashRegister::where('is_active', true)->count() === 1) {
                // COL-08: En instalaciones Mono-Caja (1 sola caja activa), si un cliente legacy no envía shift_id,
                // resolvemos el turno abierto de esa única caja sin riesgo de mezclar terminales.
                $singleOpenShift = CashShift::where('status', 'open')->latest('id')->first();
                if ($singleOpenShift) {
                    $query->where('cash_shift_id', $singleOpenShift->id);
                } else {
                    return response()->json([]);
                }
            } else {
                // En un entorno Multi-Caja, si no envían shiftId ni cash_register_id, significa que la terminal
                // actual no tiene un turno abierto. No debemos adivinar usando el último turno global.
                return response()->json([]);
            }
        }

        if ($period !== 'shift' && ! empty($cashRegisterId) && is_numeric($cashRegisterId)) {
            $query->whereHas('cashShift', fn ($q) => $q->where('cash_register_id', (int) $cashRegisterId));
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
            'items.product:id,name,internal_code,is_sold_by_weight,selling_price',
            'items.product.offers',
            'user:id,name',
            'cashier:id,name',
            'cashShift.cashRegister:id,name',
            'customer:id,name,document_number',
            'payments.paymentMethod:id,name,code,is_cash',
            'electronicInvoice',
            'deliveryNote.items.product:id,name,internal_code,is_sold_by_weight',
        ]);

        return response()->json($sale);
    }

    /**
     * GET /api/sales/pending
     * Devuelve todos los tickets en espera (status = 'pending').
     */
    public function pending(Request $request)
    {
        $query = Sale::with([
            'items.product:id,name,internal_code,stock,is_sold_by_weight,selling_price,cost_price,price_wholesale,price_card',
            'user:id,name',
            'cashier:id,name',
            'cashShift.cashRegister:id,name',
            'customer',
            'payments.paymentMethod:id,name,code,is_cash',
        ])->select('sales.*')
            ->where('status', 'pending');

        if ($request->filled('cash_shift_id') && is_numeric($request->query('cash_shift_id'))) {
            $query->where('cash_shift_id', (int) $request->query('cash_shift_id'));
        } elseif ($request->filled('cash_register_id') && is_numeric($request->query('cash_register_id'))) {
            $regId = (int) $request->query('cash_register_id');
            $query->whereHas('cashShift', fn ($q) => $q->where('cash_register_id', $regId));
        }

        $sales = $query->latest()->get();

        return response()->json($sales);
    }

    /**
     * PUT /api/sales/{sale}/pay
     */
    public function pay(PaySaleRequest $request, Sale $sale, SaleService $saleService, DiscountAuthorizationService $discountAuthService)
    {
        $validated = $request->validated();

        // Fix F1-CRIT-01: Validar autorización de descuentos en ítems modificados al cobrar tickets en espera
        if (! empty($validated['items'])) {
            $discountResponse = $discountAuthService->validateItemDiscounts($request, $validated['items'], $sale);
            if ($discountResponse !== null) {
                return $discountResponse;
            }
        }

        // FINDING-CC-01: Resolver PIN de supervisor explícito (ej. para autorizar exceso sobre credit_limit)
        $pinResponse = $discountAuthService->resolveExplicitAdminPin($request);
        if ($pinResponse !== null) {
            return $pinResponse;
        }

        $actingUser = $request->user() ?? $request->attributes->get('authenticated_user');
        $authorizedByAdminId = $request->attributes->get('authorized_by_admin_id')
            ?? ($actingUser?->isAdmin() ? $actingUser->id : null);

        $dto = PaySaleDTO::fromArray($validated);
        $context = SaleContextDTO::fromArray(
            $validated,
            $actingUser?->id,
            false,
            $authorizedByAdminId
        );

        try {
            $completedSale = $saleService->payPendingSale($sale, $dto, $context);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => true, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Venta #{$completedSale->id} cobrada correctamente.",
            'sale' => $completedSale->fresh()->load('items.product', 'user:id,name', 'cashier:id,name', 'customer', 'payments.paymentMethod:id,name,code,is_cash', 'deliveryNote.items.product'),
        ]);
    }

    /**
     * POST /api/sales/{sale}/void
     */
    public function void(VoidSaleRequest $request, Sale $sale, SaleService $saleService)
    {
        $validated = $request->validated();

        // COL-03: Compatibilidad con clientes v1.8.5 que invocan POST /api/sales/{sale}/void sin cash_shift_id
        if (empty($validated['cash_shift_id']) && $sale->status === 'completed') {
            $originShift = $sale->cash_shift_id ? CashShift::find($sale->cash_shift_id) : null;
            if ($originShift && $originShift->status === 'open') {
                $validated['cash_shift_id'] = $originShift->id;
            } elseif ($originShift && $originShift->cash_register_id) {
                $validated['cash_shift_id'] = CashShift::where('cash_register_id', $originShift->cash_register_id)
                    ->where('status', 'open')
                    ->latest('id')
                    ->value('id');
            }

            if (empty($validated['cash_shift_id'])) {
                $validated['cash_shift_id'] = CashShift::where('status', 'open')->latest('id')->value('id');
            }
        }

        $actingUser = $request->user() ?? $request->attributes->get('authenticated_user');
        $authorizedByAdminId = $request->attributes->get('authorized_by_admin_id')
            ?? ($actingUser?->isAdmin() ? $actingUser->id : null);

        if (empty($validated['void_reason'])) {
            $validated['void_reason'] = $validated['reason']
                ?? ($request->boolean('fiscal_rollback') ? 'Rollback automático por rechazo fiscal AFIP' : null);
        }

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
            'sale' => $voidedSale->fresh()->load('items.product', 'user:id,name', 'cashier:id,name', 'payments.paymentMethod:id,name,code,is_cash', 'electronicInvoice'),
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
