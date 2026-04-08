<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Models\QuoteItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Módulo de Presupuestos — solo activo cuando business_type = 'hardware_store'.
 *
 * REGLA DE ORO: Guardar un presupuesto NUNCA modifica el stock de ningún producto.
 */
class QuoteController extends Controller
{
    /**
     * Listado paginado de presupuestos.
     */
    public function index(Request $request)
    {
        $query = Quote::with('items')->latest();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('quote_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->paginate(50));
    }

    /**
     * Recupera un presupuesto con sus ítems.
     */
    public function show(Quote $quote)
    {
        return response()->json($quote->load('items'));
    }

    /**
     * Crea un nuevo presupuesto.
     * ⚠️  NO descuenta stock bajo ningún concepto.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_name'  => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'notes'          => 'nullable|string|max:1000',
            'valid_until'    => 'nullable|date|after_or_equal:today',
            'user_id'        => 'nullable|exists:users,id',
            'items'          => 'required|array|min:1',
            'items.*.product_id'   => 'nullable|integer',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.unit_price'   => 'required|numeric|min:0',
            'items.*.quantity'     => 'required|numeric|min:0.001',
        ]);

        DB::beginTransaction();
        try {
            // Calcular totales en el servidor para evitar manipulación del cliente
            $subtotal = collect($validated['items'])->sum(function ($item) {
                return round($item['unit_price'] * $item['quantity'], 2);
            });

            $quote = Quote::create([
                'quote_number'   => Quote::nextQuoteNumber(),
                'status'         => 'pending',
                'subtotal'       => $subtotal,
                'total'          => $subtotal, // Sin impuestos en esta versión MVP
                'customer_name'  => $validated['customer_name'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'notes'          => $validated['notes'] ?? null,
                'valid_until'    => $validated['valid_until'] ?? null,
                'user_id'        => $validated['user_id'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                QuoteItem::create([
                    'quote_id'     => $quote->id,
                    'product_id'   => $item['product_id'] ?? null,
                    'product_name' => $item['product_name'],
                    'unit_price'   => $item['unit_price'],
                    'quantity'     => $item['quantity'],
                    'subtotal'     => round($item['unit_price'] * $item['quantity'], 2),
                ]);
            }

            DB::commit();

            return response()->json($quote->load('items'), 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al guardar el presupuesto: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Actualiza el estado de un presupuesto (pending → approved / rejected / expired).
     */
    public function updateStatus(Request $request, Quote $quote)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,approved,rejected,expired',
        ]);

        $quote->update(['status' => $validated['status']]);

        return response()->json($quote->fresh());
    }
}
