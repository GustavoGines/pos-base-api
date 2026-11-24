<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        if (Schema::hasTable('supplier_invoice_audits')) {
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
                'cash_shift_id',
                'user_id',
                'amount',
                'payment_method',
                'funding_source',
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

    /**
     * Asistente de compras por proveedor (solo lectura):
     * - Faltantes / stock bajo de productos del proveedor
     * - Artículos comprados con mayor frecuencia / historial reciente (incluyendo último costo y cantidad)
     * - Catálogo asignado al proveedor (útil para la 1ª carga)
     * - Último comprobante vigente para clonar renglones rápidamente
     */
    public function purchaseAssistant(Supplier $supplier)
    {
        $productRelations = ['category.rubro', 'brand', 'supplier:id,name'];

        // 1. Estadísticas de compras previas vigentes (sin anulados ni Notas de Crédito)
        $statsRows = DB::table('supplier_invoice_items as sii')
            ->join('supplier_invoices as si', 'si.id', '=', 'sii.supplier_invoice_id')
            ->join('products as p', 'p.id', '=', 'sii.product_id')
            ->where('si.supplier_id', $supplier->id)
            ->whereNull('si.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('p.active', true)
            ->where('p.is_service', false)
            ->where(function ($q) {
                $q->whereNull('si.type')
                    ->orWhere('si.type', '!=', 'credit_note');
            })
            ->groupBy('sii.product_id')
            ->select([
                'sii.product_id',
                DB::raw('COUNT(DISTINCT si.id) as times_bought'),
                DB::raw('MAX(si.id) as last_invoice_id'),
                DB::raw('MAX(si.issue_date) as last_purchased_at'),
            ])
            ->orderByDesc('times_bought')
            ->orderByDesc('last_invoice_id')
            ->limit(30)
            ->get();

        $frequentIds = $statsRows->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        $lastInvoiceIds = $statsRows->pluck('last_invoice_id')->map(fn ($id) => (int) $id)->unique()->all();

        $lastItemStats = [];
        if (! empty($lastInvoiceIds) && ! empty($frequentIds)) {
            $itemRows = DB::table('supplier_invoice_items')
                ->whereIn('supplier_invoice_id', $lastInvoiceIds)
                ->whereIn('product_id', $frequentIds)
                ->orderBy('id', 'asc')
                ->get(['supplier_invoice_id', 'product_id', 'unit_cost', 'quantity']);

            foreach ($itemRows as $ir) {
                $key = ((int) $ir->supplier_invoice_id).'_'.((int) $ir->product_id);
                $lastItemStats[$key] = $ir;
            }
        }

        $frequentProductsMap = ! empty($frequentIds)
            ? Product::with($productRelations)->whereIn('id', $frequentIds)->get()->keyBy('id')
            : collect();

        $frequentProducts = [];
        foreach ($statsRows as $row) {
            $pid = (int) $row->product_id;
            /** @var Product|null $product */
            $product = $frequentProductsMap->get($pid);
            if (! $product) {
                continue;
            }
            $lastKey = ((int) $row->last_invoice_id).'_'.$pid;
            $lastRow = $lastItemStats[$lastKey] ?? null;

            $arr = $product->toArray();
            $arr['times_bought'] = (int) $row->times_bought;
            $arr['last_purchased_at'] = $row->last_purchased_at ? (string) $row->last_purchased_at : null;
            $arr['last_unit_cost'] = $lastRow ? (float) $lastRow->unit_cost : null;
            $arr['last_quantity'] = $lastRow ? (float) $lastRow->quantity : null;
            $frequentProducts[] = $arr;
        }

        // Todos los product_id comprados alguna vez a este proveedor (para detectar faltantes)
        $purchasedProductIds = DB::table('supplier_invoice_items as sii')
            ->join('supplier_invoices as si', 'si.id', '=', 'sii.supplier_invoice_id')
            ->where('si.supplier_id', $supplier->id)
            ->whereNull('si.deleted_at')
            ->where(function ($q) {
                $q->whereNull('si.type')
                    ->orWhere('si.type', '!=', 'credit_note');
            })
            ->distinct()
            ->pluck('sii.product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // 2. Productos con stock 0 o debajo de su mínimo
        $lowStockProducts = Product::with($productRelations)
            ->where('active', true)
            ->where('is_service', false)
            ->where(function ($q) use ($supplier, $purchasedProductIds) {
                $q->where('supplier_id', $supplier->id);
                if (! empty($purchasedProductIds)) {
                    $q->orWhereIn('id', $purchasedProductIds);
                }
            })
            ->where(function ($q) {
                $q->where('stock', '<=', 0)
                    ->orWhere(function ($mq) {
                        $mq->whereNotNull('min_stock')
                            ->where('min_stock', '>', 0)
                            ->whereColumn('stock', '<=', 'min_stock');
                    });
            })
            ->orderByRaw('(stock - COALESCE(min_stock, 0)) ASC')
            ->orderBy('name', 'asc')
            ->limit(30)
            ->get()
            ->map(fn (Product $p) => $p->toArray())
            ->values()
            ->all();

        // 3. Catálogo vinculado directamente al proveedor
        $catalogProducts = Product::with($productRelations)
            ->where('active', true)
            ->where('is_service', false)
            ->where('supplier_id', $supplier->id)
            ->orderBy('name', 'asc')
            ->limit(40)
            ->get()
            ->map(fn (Product $p) => $p->toArray())
            ->values()
            ->all();

        // 4. Última factura/remito de compra vigente para clonar renglones en 1 clic
        $lastInv = SupplierInvoice::where('supplier_id', $supplier->id)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('type')
                    ->orWhere('type', '!=', 'credit_note');
            })
            ->with(['items.product' => fn ($q) => $q->with($productRelations)])
            ->orderBy('issue_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        $lastInvoicePayload = null;
        if ($lastInv) {
            $lastItems = $lastInv->items
                ->filter(fn ($it) => $it->product !== null && ! $it->product->trashed() && ! $it->product->is_service)
                ->map(fn ($it) => [
                    'product_id' => $it->product_id,
                    'quantity' => (float) $it->quantity,
                    'unit_cost' => (float) $it->unit_cost,
                    'product' => $it->product->toArray(),
                ])
                ->values()
                ->all();

            if (! empty($lastItems)) {
                $lastInvoicePayload = [
                    'id' => $lastInv->id,
                    'invoice_number' => $lastInv->formatted_number ?: ($lastInv->invoice_number ?: ('#'.$lastInv->id)),
                    'issue_date' => $lastInv->issue_date?->toIso8601String(),
                    'items' => $lastItems,
                ];
            }
        }

        return response()->json([
            'low_stock_products' => $lowStockProducts,
            'frequent_products' => $frequentProducts,
            'catalog_products' => $catalogProducts,
            'last_invoice' => $lastInvoicePayload,
        ], 200);
    }
}
