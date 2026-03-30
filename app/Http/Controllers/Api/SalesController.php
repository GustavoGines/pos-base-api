<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'items.product:id,name,is_sold_by_weight',
            'user:id,name',
            'payments.paymentMethod:id,name,code,is_cash',
        ])->latest();

        if ($period === 'today') {
            $query->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()]);
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
                $openShift = CashShift::whereNull('closed_at')->latest()->first();
                if ($openShift) {
                    $query->where('cash_shift_id', $openShift->id);
                } else {
                    return response()->json([]);
                }
            }
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return response()->json($query->get());
    }

    /**
     * GET /api/sales/pending
     * Devuelve todos los tickets en espera (status = 'pending').
     */
    public function pending()
    {
        $sales = Sale::with([
            'items.product:id,name,is_sold_by_weight',
            'user:id,name',
            'payments.paymentMethod:id,name,code,is_cash',
        ])
            ->where('status', 'pending')
            ->latest()
            ->get();

        return response()->json($sales);
    }

    /**
     * PUT /api/sales/{sale}/pay
     * Cobra una venta en espera: cambia status a 'completed' y registra el pago.
     */
    public function pay(Request $request, Sale $sale)
    {
        if ($sale->status !== 'pending') {
            return response()->json(['message' => 'Esta venta no está en estado pendiente.'], 422);
        }

        $validated = $request->validate([
            'payments'               => 'required|array|min:1',
            'payments.*.payment_method_id' => 'required|integer|exists:payment_methods,id',
            'payments.*.base_amount'      => 'required|numeric|min:0',
            'payments.*.surcharge_amount' => 'required|numeric|min:0',
            'payments.*.total_amount'     => 'required|numeric|min:0',
            'total_surcharge'        => 'required|numeric|min:0',
            'tendered_amount'        => 'nullable|numeric|min:0',
            'change_amount'          => 'nullable|numeric',
            'items'                  => 'nullable|array',
            'items.*.product_id'     => 'required_with:items|integer|exists:products,id',
            'items.*.quantity'       => 'required_with:items|numeric|min:0.001',
            'items.*.unit_price'     => 'required_with:items|numeric',
            'items.*.subtotal'       => 'required_with:items|numeric',
            'user_id'                => 'nullable|integer|exists:users,id',
        ]);

        DB::transaction(function () use ($validated, $sale, $request) {
            $userId = $validated['user_id'] ?? $request->input('user_id');

            // Si el cliente envía 'items', hubo Order Recall y modificaciones
            if (isset($validated['items'])) {
                $originalQuantities = [];
                foreach ($sale->items as $oldItem) {
                    $originalQuantities[$oldItem->product_id] = ($originalQuantities[$oldItem->product_id] ?? 0) + $oldItem->quantity;
                }

                $newQuantities = [];
                $newTotal = 0.0;
                foreach ($validated['items'] as $newItem) {
                    $newQuantities[$newItem['product_id']] = ($newQuantities[$newItem['product_id']] ?? 0) + $newItem['quantity'];
                    $newTotal += $newItem['subtotal'];
                }

                // Reconciliación de stock
                $allProductIds = array_unique(array_merge(array_keys($originalQuantities), array_keys($newQuantities)));
                foreach ($allProductIds as $productId) {
                    $oldQty = $originalQuantities[$productId] ?? 0;
                    $newQty = $newQuantities[$productId] ?? 0;
                    $diff = $newQty - $oldQty; // Positivo = se agregaron más, Negativo = quitó
                    
                    if ($diff != 0) {
                        $product = \App\Models\Product::find($productId);
                        if ($product) {
                            $product->stock -= $diff;
                            $product->save();

                            \App\Models\StockMovement::create([
                                'product_id' => $productId,
                                'user_id'    => $userId,
                                'type'       => $diff > 0 ? 'sale' : 'in',
                                'quantity'   => $diff > 0 ? -$diff : abs($diff),
                                'notes'      => sprintf(
                                    "Ajuste Recall Venta #%d (Modificó de %g a %g)", 
                                    $sale->id, $oldQty, $newQty
                                ),
                            ]);
                        }
                    }
                }

                // Borrar items viejos y reinsertar los nuevos
                $sale->items()->delete();
                foreach ($validated['items'] as $itemData) {
                    $product = \App\Models\Product::find($itemData['product_id']);
                    if ($product) {
                        $sale->items()->create([
                            'product_id'   => $product->id,
                            'product_name' => $product->name,
                            'quantity'     => $itemData['quantity'],
                            'unit_price'   => $itemData['unit_price'],
                            'subtotal'     => $itemData['subtotal'],
                        ]);
                    }
                }

                $sale->setAttribute('total', $newTotal);
            }

            foreach ($validated['payments'] as $payment) {
                $sale->payments()->create([
                    'payment_method_id' => $payment['payment_method_id'],
                    'base_amount'       => $payment['base_amount'],
                    'surcharge_amount'  => $payment['surcharge_amount'],
                    'total_amount'      => $payment['total_amount'],
                ]);
            }

            $currentDue = $sale->amount_due > 0 ? $sale->amount_due : 0; // for cuenta corriente, logic could be more complex, but we assume paid in full for pending recall for now.

            $sale->update([
                'status'          => 'completed',
                'tendered_amount' => $validated['tendered_amount'] ?? $sale->total,
                'change_amount'   => $validated['change_amount'] ?? 0,
                'total_surcharge' => $validated['total_surcharge'] ?? 0,
                'payment_status'  => current($validated['payments'])['payment_method_id'] === 5 ? 'pending' : 'paid', // simplistic cc fallback (5=cc)
            ]);
        });

        return response()->json([
            'message' => "Venta #{$sale->id} cobrada correctamente.",
            'sale'    => $sale->fresh()->load('items.product', 'user:id,name'),
        ]);
    }

    /**
     * POST /api/sales/{sale}/void
     * Anula una venta: devuelve stock a cada producto y registra movimiento de reversión.
     */
    public function void(Request $request, Sale $sale)
    {
        if ($sale->status === 'voided') {
            return response()->json(['message' => 'Esta venta ya está anulada.'], 422);
        }

        $userId = $request->input('user_id');

        DB::transaction(function () use ($sale, $userId) {
            // Devolver stock de cada ítem al producto
            foreach ($sale->items as $item) {
                if ($item->product) {
                    $item->product->increment('stock', $item->quantity);

                    StockMovement::create([
                        'product_id' => $item->product_id,
                        'user_id'    => $userId ?? null,
                        'type'       => 'in',
                        'quantity'   => $item->quantity,
                        'notes'      => "Reversión por anulación de Venta #{$sale->id}",
                    ]);
                }
            }

            // Marcar la venta como anulada
            $sale->update(['status' => 'voided']);
        });

        return response()->json([
            'message' => "Venta #{$sale->id} anulada correctamente. El stock fue restaurado.",
            'sale'    => $sale->fresh()->load('items.product', 'user:id,name', 'payments.paymentMethod:id,name,code,is_cash'),
        ]);
    }
}
