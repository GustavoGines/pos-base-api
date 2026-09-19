<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $query = Supplier::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('cuit', 'LIKE', "%{$search}%")
                  ->orWhere('contact_name', 'LIKE', "%{$search}%");
            });
        }

        return response()->json($query->orderBy('name')->get(), 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'cuit' => ['nullable', 'string', 'max:50', Rule::unique('suppliers', 'cuit')->whereNull('deleted_at')],
            'tax_category' => 'nullable|string|max:50',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'balance' => 'nullable|numeric',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['balance'] = $data['balance'] ?? 0;
        $data['is_active'] = $data['is_active'] ?? true;

        $supplier = Supplier::create($data);
        return response()->json($supplier, 201);
    }

    public function show(Supplier $supplier)
    {
        return response()->json($supplier, 200);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'cuit' => ['nullable', 'string', 'max:50', Rule::unique('suppliers', 'cuit')->ignore($supplier->id)->whereNull('deleted_at')],
            'tax_category' => 'nullable|string|max:50',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'balance' => 'nullable|numeric',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $supplier->update($validator->validated());
        return response()->json($supplier, 200);
    }

    public function destroy(Supplier $supplier)
    {
        if ($supplier->balance != 0) {
            return response()->json(['message' => 'No se puede eliminar un proveedor con saldo pendiente.'], 422);
        }
        if ($supplier->products()->count() > 0) {
            return response()->json(['message' => 'No se puede eliminar un proveedor que tiene productos asociados.'], 422);
        }

        $supplier->delete();
        return response()->json(['message' => 'Proveedor eliminado con éxito'], 200);
    }
    public function currentAccount(Supplier $supplier)
    {
        $invoices = \App\Models\SupplierInvoice::where('supplier_id', $supplier->id)
            ->select('id', 'amount', 'type', 'issue_date as date', 'invoice_number', \DB::raw("'invoice' as source"))
            ->get();

        $payments = \App\Models\CashMovement::where('supplier_id', $supplier->id)
            ->whereIn('type', ['expense', 'supplier_payment', 'deposit'])
            ->select('id', 'amount', 'type', 'created_at as date', 'receipt_number as invoice_number', \DB::raw("'payment' as source"))
            ->get();

        $combined = $invoices->concat($payments)->sortBy('date')->values();

        $runningBalance = 0;
        $history = $combined->map(function ($item) use (&$runningBalance) {
            $isIncrease = ($item->source === 'invoice' && $item->type === 'invoice') || 
                          ($item->source === 'payment' && $item->type === 'deposit');
            
            $isDecrease = ($item->source === 'invoice' && $item->type === 'credit_note') || 
                          ($item->source === 'payment' && in_array($item->type, ['expense', 'supplier_payment']));

            if ($isIncrease) $runningBalance += $item->amount;
            if ($isDecrease) $runningBalance -= $item->amount;

            $item->running_balance = $runningBalance;
            return $item;
        });

        return response()->json([
            'supplier' => $supplier,
            'history' => $history->reverse()->values() // Más recientes primero
        ], 200);
    }
}
