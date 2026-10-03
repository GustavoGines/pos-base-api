<?php

namespace App\Http\Controllers\Api;

use App\Constants\Permissions;
use App\DTOs\ProcessSaleDTO;
use App\DTOs\SaleContextDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessSaleRequest;
use App\Models\Product;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

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

        $sale = $saleService->executeSale($dto, $context);

        return response()->json([
            'message' => 'Venta registrada correctamente',
            'sale' => $sale->load('items.product', 'user:id,name', 'cashier:id,name', 'payments.paymentMethod:id,name,code,is_cash'),
        ], 201);
    }

    /**
     * Valida si algún ítem posee un precio menor al de su tramo correspondiente
     * y exige el permiso apply_discounts o PIN de supervisor con Compound Rate Limiting.
     */
    protected function validateItemDiscounts(Request $request, array $items): ?\Illuminate\Http\JsonResponse
    {
        $user = $request->user() ?? $request->attributes->get('authenticated_user');
        if (! $user && $request->header('X-Session-Token')) {
            $user = User::withoutGlobalScope('visible')
                ->where('session_token', $request->header('X-Session-Token'))
                ->first();
        }
        if (! $user && $request->input('user_id')) {
            $user = User::withoutGlobalScope('visible')->find($request->input('user_id'));
        }

        if ($user && ($user->isAdmin() || $user->hasPermission('all') || $user->hasPermission(Permissions::APPLY_DISCOUNTS))) {
            return null;
        }

        $throttleKey = 'pin_attempts:' . ($user ? $user->id : 'guest') . ':' . $request->ip();
        $authorizedAdmin = null;

        foreach ($items as $item) {
            /** @var \App\Models\Product|null $product */
            $product = Product::with('priceTiers')->find($item['product_id']);
            if (! $product) {
                continue;
            }

            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);

            // Calcular precio esperado según tramo de volumen
            $expectedPrice = (float) $product->getPriceForQuantity($quantity);

            // Margen de tolerancia de 0.01
            if ($unitPrice < ($expectedPrice - 0.01)) {
                if ($authorizedAdmin !== null) {
                    continue;
                }

                $rawHeaderPin = $request->header('X-Admin-Pin');
                $rawDiscountPin = $request->input('discount_pin');
                $rawBodyPin = $request->input('admin_pin');

                $adminPin = (is_string($rawHeaderPin) && trim($rawHeaderPin) !== '')
                    ? trim($rawHeaderPin)
                    : ((is_string($rawDiscountPin) && trim($rawDiscountPin) !== '')
                        ? trim($rawDiscountPin)
                        : ((is_string($rawBodyPin) && trim($rawBodyPin) !== '') ? trim($rawBodyPin) : null));

                if ($adminPin !== null) {
                    if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
                        $seconds = RateLimiter::availableIn($throttleKey);

                        return response()->json([
                            'message' => "Demasiados intentos erróneos de PIN. Operación bloqueada temporalmente por {$seconds} segundos.",
                            'error_code' => 'PIN_LOCKED_TEMPORARILY',
                            'retry_after' => $seconds,
                        ], 429);
                    }

                    $admins = User::withoutGlobalScope('visible')
                        ->where('role', 'admin')
                        ->whereNotNull('pin')
                        ->get();

                    foreach ($admins as $admin) {
                        if (Hash::check($adminPin, $admin->pin)) {
                            $authorizedAdmin = $admin;
                            break;
                        }
                    }

                    if ($authorizedAdmin) {
                        RateLimiter::clear($throttleKey);
                        $request->attributes->set('authorized_by_admin_id', $authorizedAdmin->id);
                        $request->attributes->set('authorized_by_admin', $authorizedAdmin);
                        continue;
                    }

                    RateLimiter::hit($throttleKey, 300);

                    return response()->json([
                        'message' => 'El PIN de administrador ingresado es incorrecto.',
                        'error_code' => 'INVALID_ADMIN_PIN',
                    ], 403);
                }

                return response()->json([
                    'message' => "Se aplicaron descuentos o rebajas de precio manuales no autorizadas en el producto '{$product->name}' (esperado: \${$expectedPrice}, enviado: \${$unitPrice}). Se requiere permiso 'apply_discounts' o PIN de supervisor.",
                    'error_code' => 'UNAUTHORIZED_PRICE_DISCOUNT',
                    'product_id' => $product->id,
                    'expected_price' => $expectedPrice,
                    'submitted_price' => $unitPrice,
                ], 403);
            }
        }

        return null;
    }
}
