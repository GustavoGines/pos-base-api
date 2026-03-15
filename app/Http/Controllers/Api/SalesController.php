<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegisterShift;
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

        $query = Sale::with([
            'items.product:id,name,is_sold_by_weight',
        ])->latest();

        if ($period === 'today') {
            $query->whereDate('created_at', DB::raw('CURRENT_DATE'));
        } elseif ($period === 'month') {
            $query->whereMonth('created_at', date('m'))
                  ->whereYear('created_at', date('Y'));
        } elseif ($period === 'year') {
            $query->whereYear('created_at', date('Y'));
        } elseif ($period === 'all') {
            // Sin filtro de fecha
        } else {
            // Comportamiento por defecto ('shift')
            if ($shiftId) {
                $query->where('cash_register_shift_id', $shiftId);
            } else {
                $openShift = CashRegisterShift::whereNull('closed_at')->latest()->first();
                if ($openShift) {
                    $query->where('cash_register_shift_id', $openShift->id);
                } else {
                    return response()->json([]);
                }
            }
        }

        return response()->json($query->get());
    }

    /**
     * POST /api/sales/{sale}/void
     * Anula una venta: devuelve stock a cada producto y registra movimiento de reversión.
     */
    public function void(Sale $sale)
    {
        if ($sale->status === 'voided') {
            return response()->json(['message' => 'Esta venta ya está anulada.'], 422);
        }

        DB::transaction(function () use ($sale) {
            // Devolver stock de cada ítem al producto
            foreach ($sale->items as $item) {
                if ($item->product) {
                    $item->product->increment('stock', $item->quantity);

                    StockMovement::create([
                        'product_id' => $item->product_id,
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
            'sale'    => $sale->fresh()->load('items.product'),
        ]);
    }
}
