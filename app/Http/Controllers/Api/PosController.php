<?php

namespace App\Http\Controllers\Api;

use App\DTOs\ProcessSaleDTO;
use App\DTOs\SaleContextDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessSaleRequest;
use App\Models\Product;
use App\Services\DiscountAuthorizationService;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosController extends Controller
{
    public function __construct(
        protected DiscountAuthorizationService $discountAuthService
    ) {}
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
                    ->orWhere(function ($n) use ($query) {
                        $n->matchingSearch($query, ['name']);
                    });

                if (is_numeric($query)) {
                    $q->orWhere('id', $query);
                }
            })
            ->with(['children', 'priceTiers', 'category.rubro', 'brand'])
            ->get();

        return response()->json($products);
    }

    public function processSale(ProcessSaleRequest $request, SaleService $saleService)
    {
        $validated = $request->validated();
        if (empty($validated['client_transaction_id']) && $request->hasHeader('X-Idempotency-Key')) {
            $headerKey = trim((string) $request->header('X-Idempotency-Key'));
            if ($headerKey !== '' && strlen($headerKey) <= 64) {
                $validated['client_transaction_id'] = $headerKey;
            }
        }

        // 1. Validar autorización de descuentos o rebajas manuales
        $discountResponse = $this->validateItemDiscounts($request, $validated['items']);
        if ($discountResponse !== null) {
            return $discountResponse;
        }

        $actingUser = $request->user() ?? $request->attributes->get('authenticated_user');
        $authorizedByAdminId = $request->attributes->get('authorized_by_admin_id')
            ?? ($actingUser?->isAdmin() ? $actingUser->id : null);

        $dto = ProcessSaleDTO::fromArray($validated);
        $context = SaleContextDTO::fromArray(
            $validated,
            $actingUser?->id,
            false,
            $authorizedByAdminId
        );

        try {
            $sale = $saleService->executeSale($dto, $context);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => true, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Venta registrada correctamente',
            'sale' => $sale->load('items.product', 'user:id,name', 'cashier:id,name', 'payments.paymentMethod:id,name,code,is_cash'),
        ], 201);
    }

    /**
     * Valida si algún ítem posee un precio menor al de su tramo correspondiente
     * y exige el permiso apply_discounts o PIN de supervisor con Compound Rate Limiting.
     */
    protected function validateItemDiscounts(Request $request, array $items): ?JsonResponse
    {
        return $this->discountAuthService->validateItemDiscounts($request, $items);
    }
}
