<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Quote;
use App\Models\ThirdPartyCheck;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    public function searchProducts(Request $request)
    {
        $query = $request->query('query');
        if (!$query) {
            return response()->json([]);
        }

        $plu = is_numeric($query) ? str_pad($query, 5, '0', STR_PAD_LEFT) : $query;

        $products = Product::where('active', true)
            ->where(function($q) use ($query, $plu) {
                $q->where('barcode', $query)
                  ->orWhere('internal_code', $query)
                  ->orWhere('internal_code', $plu)
                  ->orWhere('name', 'like', "%{$query}%");
                
                if (is_numeric($query)) {
                    $q->orWhere('id', $query);
                }
            })
            ->with(['children', 'priceTiers', 'category', 'brand'])
            ->get();

        return response()->json($products);
    }

    public function processSale(\App\Http\Requests\ProcessSaleRequest $request, \App\Services\SaleService $saleService)
    {
        $validated = $request->validated();
        
        if (isset($validated['payments'])) {
            $hasCuentaCorriente = collect($validated['payments'])->contains(function ($p) {
                $method = \App\Models\PaymentMethod::find($p['payment_method_id']);
                return $method && $method->code === 'cuenta_corriente';
            });
            if ($hasCuentaCorriente && empty($validated['customer_id'])) {
                return response()->json([
                    'message' => 'Error de validación.',
                    'errors'  => ['customer_id' => ['Debe seleccionar un cliente para ventas en Cuenta Corriente.']],
                ], 422);
            }
        }

        $dto = \App\DTOs\ProcessSaleDTO::fromRequest($validated);
        $context = \App\DTOs\SaleContextDTO::fromArray(
            $validated, 
            $request->user()?->id ?? $request->attributes->get('authenticated_user')?->id
        );
        
        $sale = $saleService->executeSale($dto, $context);

        return response()->json([
            'message' => 'Venta registrada correctamente',
            'sale'    => $sale->load('items.product', 'user:id,name', 'cashier:id,name', 'payments.paymentMethod:id,name,code,is_cash'),
        ], 201);
    }
}