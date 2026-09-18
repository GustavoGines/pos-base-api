<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Supplier;
use App\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;

class SupplierInvoiceController extends Controller
{
    public function store(Request $request, Supplier $supplier)
    {
        $validated = $request->validate([
            'type' => 'required|string|in:invoice,credit_note',
            'amount' => 'required|numeric|min:0.01',
            'invoice_number' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'issue_date' => 'nullable|date',
        ]);

        try {
            DB::transaction(function () use ($validated, $supplier, $request) {
                // Crear la factura o nota de crédito
                $supplier->invoices()->create([
                    'type' => $validated['type'],
                    'amount' => $validated['amount'],
                    'invoice_number' => $validated['invoice_number'],
                    'description' => $validated['description'],
                    'issue_date' => $validated['issue_date'] ?? now(),
                    'user_id' => $request->attributes->get('authenticated_user')->id ?? null,
                ]);

                // Factura aumenta la deuda, Nota de Crédito la disminuye (Saldo a favor)
                if ($validated['type'] === 'credit_note') {
                    $supplier->decrement('balance', $validated['amount']);
                } else {
                    $supplier->increment('balance', $validated['amount']);
                }
            });

            return response()->json([
                'message' => 'Factura/Remito cargado exitosamente',
                'supplier' => $supplier->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al registrar la factura: ' . $e->getMessage()], 500);
        }
    }
}
