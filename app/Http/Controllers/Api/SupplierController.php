<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $query = Supplier::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
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
            'is_active' => 'boolean',
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
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $supplier->update($validator->validated());

        return response()->json($supplier, 200);
    }

    public function destroy(Supplier $supplier)
    {
        return DB::transaction(function () use ($supplier) {
            // CP-09: Bloqueo pesimista antes de validar saldo y eliminar
            $lockedSupplier = Supplier::where('id', $supplier->id)->lockForUpdate()->firstOrFail();

            if (abs((float) $lockedSupplier->balance) > 0.009) {
                return response()->json(['message' => 'No se puede eliminar un proveedor con saldo pendiente.'], 422);
            }
            if ($lockedSupplier->products()->count() > 0) {
                return response()->json(['message' => 'No se puede eliminar un proveedor que tiene productos asociados.'], 422);
            }

            $lockedSupplier->delete();

            return response()->json(['message' => 'Proveedor eliminado con éxito'], 200);
        });
    }

    public function currentAccount(Supplier $supplier)
    {
        $relations = [
            'user:id,name',
            'lastEditor:id,name',
            'items.product:id,name,internal_code,barcode,unit_type,is_sold_by_weight,selling_price,cost_price',
        ];
        if (\Illuminate\Support\Facades\Schema::hasTable('supplier_invoice_audits')) {
            $relations[] = 'audits.user:id,name';
            $relations[] = 'audits.authorizer:id,name';
        }

        $invoices = SupplierInvoice::where('supplier_id', $supplier->id)
            ->with($relations)
            ->get()
            ->map(function (SupplierInvoice $inv) {
                $formatted = app(SupplierInvoiceController::class)->formatInvoice($inv);
                $formatted['date'] = $inv->issue_date?->toIso8601String();
                $formatted['source'] = 'invoice';

                return (object) $formatted;
            });

        $payments = CashMovement::where('supplier_id', $supplier->id)
            ->with('user:id,name')
            ->whereIn('type', ['expense', 'supplier_payment', 'deposit'])
            ->select(
                'id',
                'user_id',
                'amount',
                'type',
                'description',
                'receipt_file_url',
                'created_at as date',
                'receipt_number as invoice_number',
                \DB::raw("'payment' as source")
            )
            ->get()
            ->map(function (CashMovement $mov) {
                $arr = $mov->toArray();
                $arr['user_name'] = $mov->user?->name;
                $arr['items_count'] = 0;
                $arr['items'] = [];

                return (object) $arr;
            });

        $combined = $invoices->concat($payments)->sortBy('date')->values();

        $runningBalance = 0;
        $history = $combined->map(function ($item) use (&$runningBalance) {
            $isIncrease = ($item->source === 'invoice' && in_array($item->type, ['invoice', 'delivery_note'], true)) ||
                          ($item->source === 'payment' && $item->type === 'deposit');

            $isDecrease = ($item->source === 'invoice' && $item->type === 'credit_note') ||
                          ($item->source === 'payment' && in_array($item->type, ['expense', 'supplier_payment'], true));

            if ($isIncrease) {
                $runningBalance += $item->amount;
            }
            if ($isDecrease) {
                $runningBalance -= $item->amount;
            }

            $item->running_balance = round($runningBalance, 2);

            return $item;
        });

        return response()->json([
            'supplier' => $supplier,
            'history' => $history->reverse()->values(), // Más recientes primero
        ], 200);
    }
}
