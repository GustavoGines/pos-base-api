<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    /**
     * GET /api/payment-methods
     * Devuelve todos los métodos activos ordenados, para poblar el Checkout.
     */
    public function index()
    {
        return response()->json(PaymentMethod::active()->get());
    }

    /**
     * POST /api/payment-methods
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:100',
            'code'            => 'required|string|max:50|unique:payment_methods,code',
            'surcharge_type'  => 'required|in:none,percent,fixed',
            'surcharge_value' => 'required|numeric|min:0',
            'is_cash'         => 'required|boolean',
            'sort_order'      => 'nullable|integer|min:0',
        ]);

        $method = PaymentMethod::create($validated);
        return response()->json($method, 201);
    }

    /**
     * PUT /api/payment-methods/{id}
     */
    public function update(Request $request, PaymentMethod $paymentMethod)
    {
        $validated = $request->validate([
            'name'            => 'sometimes|string|max:100',
            'surcharge_type'  => 'sometimes|in:none,percent,fixed',
            'surcharge_value' => 'sometimes|numeric|min:0',
            'is_cash'         => 'sometimes|boolean',
            'is_active'       => 'sometimes|boolean',
            'sort_order'      => 'sometimes|integer|min:0',
        ]);

        $paymentMethod->update($validated);
        return response()->json($paymentMethod);
    }

    /**
     * DELETE /api/payment-methods/{id}
     * Soft-disable: no borra para preservar historial de sale_payments.
     */
    public function destroy(PaymentMethod $paymentMethod)
    {
        // Nunca borrar métodos con pagos históricos vinculados
        if ($paymentMethod->salePayments()->exists()) {
            $paymentMethod->update(['is_active' => false]);
            return response()->json(['message' => 'Método desactivado (tiene pagos históricos asociados).']);
        }

        $paymentMethod->delete();
        return response()->json(['message' => 'Método de pago eliminado.']);
    }
}
