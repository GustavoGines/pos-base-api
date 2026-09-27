<?php

namespace App\Http\Controllers\Api;

use App\DTOs\ProcessSaleDTO;
use App\DTOs\SaleContextDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessSaleRequest;
use App\Models\Product;
use App\Services\SaleService;
use Illuminate\Http\Request;

class PosController extends Controller
{
    public function searchProducts(Request $request)
    {
        $query = $request->query('query');
        if (! $query) {
            return response()->json([]);
        }

        $plu = is_numeric($query) ? str_pad($query, 5, '0', STR_PAD_LEFT) : $query;

        $products = Product::where('active', true)
            ->where(function ($q) use ($query, $plu) {
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

    public function processSale(ProcessSaleRequest $request, SaleService $saleService)
    {
        $validated = $request->validated();

        $dto = ProcessSaleDTO::fromArray($validated);
        $context = SaleContextDTO::fromArray(
            $validated,
            $request->user()?->id ?? $request->attributes->get('authenticated_user')?->id
        );

        $sale = $saleService->executeSale($dto, $context);

        return response()->json([
            'message' => 'Venta registrada correctamente',
            'sale' => $sale->load('items.product', 'user:id,name', 'cashier:id,name', 'payments.paymentMethod:id,name,code,is_cash'),
        ], 201);
    }
}
