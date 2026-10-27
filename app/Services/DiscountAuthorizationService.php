<?php

namespace App\Services;

use App\Constants\Permissions;
use App\Models\BusinessSetting;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class DiscountAuthorizationService
{
    /**
     * Valida si algún ítem posee un precio menor al de su tramo correspondiente,
     * oferta vigente o lista oficial, y exige el permiso apply_discounts o PIN
     * de supervisor con Compound Rate Limiting.
     *
     * Si se provee $existingSale (ej. al cobrar una venta en espera en payPendingSale),
     * se respetan los precios unitarios que ya habían sido autorizados y persistidos
     * al momento de poner el ticket en espera.
     */
    public function validateItemDiscounts(Request $request, array $items, ?Sale $existingSale = null): ?JsonResponse
    {
        if (empty($items)) {
            return null;
        }

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

        $throttleKey = 'pin_attempts:'.($user ? $user->id : 'guest').':'.$request->ip();
        $authorizedAdmin = null;

        // Pre-cargar todos los productos del carrito en una sola consulta (Fix N+1 F1-BAJA-12)
        $productIds = array_values(array_unique(array_filter(array_column($items, 'product_id'))));
        $productsById = Product::with('priceTiers')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        // Mapa de precios previamente autorizados en el ticket en espera (Salvaguarda v1.8.5)
        // o en un Presupuesto formal recuperado (quote_id)
        $previouslyAuthorizedPrices = [];
        if ($existingSale !== null) {
            $existingSale->loadMissing('items');
            foreach ($existingSale->items as $oldItem) {
                $pid = (int) $oldItem->product_id;
                $oldPrice = round((float) $oldItem->unit_price, 2);
                if (! isset($previouslyAuthorizedPrices[$pid]) || $oldPrice < $previouslyAuthorizedPrices[$pid]) {
                    $previouslyAuthorizedPrices[$pid] = $oldPrice;
                }
            }
        }

        $quoteId = $request->input('quote_id');
        if ($quoteId && is_numeric($quoteId)) {
            $quote = \App\Models\Quote::with('items')->find((int) $quoteId);
            if ($quote) {
                foreach ($quote->items as $quoteItem) {
                    if (! $quoteItem->product_id) {
                        continue;
                    }
                    $pid = (int) $quoteItem->product_id;
                    $qPrice = round((float) $quoteItem->unit_price, 2);
                    if (! isset($previouslyAuthorizedPrices[$pid]) || $qPrice < $previouslyAuthorizedPrices[$pid]) {
                        $previouslyAuthorizedPrices[$pid] = $qPrice;
                    }
                }
            }
        }

        // Pre-cargar ofertas vigentes hoy y configuración de listas de precios
        $activeOffers = Offer::active()
            ->with('products')
            ->get()
            ->filter(fn (Offer $offer) => $offer->isCurrentDayValid());

        $advancedTiersEnabled = in_array(
            BusinessSetting::where('key', 'enable_advanced_price_tiers')->value('value'),
            ['1', 1, true, 'true'],
            true
        );
        $rawWholesalePct = BusinessSetting::where('key', 'wholesale_percentage')->value('value');
        $wholesalePct = is_numeric($rawWholesalePct) ? (float) $rawWholesalePct : -15.0;

        $customTiers = [];
        $rawCustomTiers = BusinessSetting::where('key', 'custom_price_tiers')->value('value');
        if (is_string($rawCustomTiers) && $rawCustomTiers !== '') {
            $decoded = json_decode($rawCustomTiers, true);
            if (is_array($decoded)) {
                $customTiers = $decoded;
            }
        }

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            /** @var Product|null $product */
            $product = $productsById->get($productId);
            if (! $product) {
                continue;
            }

            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);

            // Si el ítem ya integraba la venta en espera con un precio igual o menor al enviado hoy,
            // su descuento ya fue validado al crearse la orden en espera.
            if (isset($previouslyAuthorizedPrices[$productId]) && $unitPrice >= ($previouslyAuthorizedPrices[$productId] - 0.01)) {
                continue;
            }

            // 1. Calcular precio esperado según tramo de volumen
            $baseVolumetricPrice = (float) $product->getPriceForQuantity($quantity);
            $expectedPrice = $baseVolumetricPrice;

            // 2. Contemplar Ofertas / Promociones activas hoy para este producto
            $baseSellingPrice = (float) $product->selling_price;
            foreach ($activeOffers as $offer) {
                $offerProduct = $offer->products->firstWhere('id', $product->id);
                if (! $offerProduct) {
                    continue;
                }

                $promoPrice = $baseSellingPrice;
                if ($offer->type === 'percentage' && (float) $offer->discount_percentage > 0) {
                    $discount = $baseSellingPrice * ((float) $offer->discount_percentage / 100.0);
                    $promoPrice = round(max(0.0, $baseSellingPrice - $discount), 2);
                } elseif ($offer->type === 'fixed_discount' && (float) $offer->discount_amount > 0) {
                    $promoPrice = round(max(0.0, $baseSellingPrice - (float) $offer->discount_amount), 2);
                } elseif ($offer->type === 'fixed_price') {
                    $pivotPrice = $offerProduct->pivot?->special_price;
                    if ($pivotPrice !== null && (float) $pivotPrice > 0) {
                        $promoPrice = round((float) $pivotPrice, 2);
                    } elseif ((float) $offer->discount_amount > 0) {
                        // Fix F1-MED-05: Homologado con OfferController y Flutter offer_model.dart
                        $promoPrice = round(max(0.0, $baseSellingPrice - (float) $offer->discount_amount), 2);
                    }
                }

                if ($promoPrice > 0 && $promoPrice < $expectedPrice) {
                    $expectedPrice = $promoPrice;
                }
            }

            // 3. Verificar si el precio enviado corresponde a una Lista de Precios oficial (Mayorista / Personalizada)
            if ($unitPrice < ($expectedPrice - 0.01)) {
                $validTierPrices = [];

                if ($product->price_wholesale !== null && (float) $product->price_wholesale > 0) {
                    $validTierPrices[] = round((float) $product->price_wholesale, 2);
                }

                if ($advancedTiersEnabled) {
                    $wholesaleFactor = 1.0 + ($wholesalePct / 100.0);
                    $validTierPrices[] = round($baseVolumetricPrice * $wholesaleFactor, 2);
                    $validTierPrices[] = round($baseSellingPrice * $wholesaleFactor, 2);

                    foreach ($customTiers as $tier) {
                        if (is_array($tier) && isset($tier['modifier']) && is_numeric($tier['modifier'])) {
                            $customFactor = 1.0 + ((float) $tier['modifier'] / 100.0);
                            $validTierPrices[] = round($baseVolumetricPrice * $customFactor, 2);
                            $validTierPrices[] = round($baseSellingPrice * $customFactor, 2);
                        }
                    }
                }

                $matchesOfficialTier = false;
                foreach ($validTierPrices as $tierPrice) {
                    if ($tierPrice > 0 && abs($unitPrice - $tierPrice) <= 0.015) {
                        $matchesOfficialTier = true;
                        break;
                    }
                }

                if ($matchesOfficialTier) {
                    continue;
                }
            }

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

    /**
     * FINDING-CC-01: Si la petición incluye explícitamente X-Admin-Pin o admin_pin (por ejemplo,
     * para autorizar un exceso sobre credit_limit en Cuenta Corriente cuando no hubo descuentos de ítems),
     * valida el PIN contra los supervisores/administradores y registra authorized_by_admin_id.
     */
    public function resolveExplicitAdminPin(Request $request): ?JsonResponse
    {
        if ($request->attributes->get('authorized_by_admin_id') !== null) {
            return null;
        }

        $user = $request->user() ?? $request->attributes->get('authenticated_user');
        if ($user && ($user->isAdmin() || $user->hasPermission('all'))) {
            $request->attributes->set('authorized_by_admin_id', $user->id);

            return null;
        }

        $rawHeaderPin = $request->header('X-Admin-Pin');
        $rawBodyPin = $request->input('admin_pin');
        $adminPin = (is_string($rawHeaderPin) && trim($rawHeaderPin) !== '')
            ? trim($rawHeaderPin)
            : ((is_string($rawBodyPin) && trim($rawBodyPin) !== '') ? trim($rawBodyPin) : null);

        if ($adminPin === null) {
            return null;
        }

        $throttleKey = 'pin_attempts:'.($user ? $user->id : 'guest').':'.$request->ip();
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
                RateLimiter::clear($throttleKey);
                $request->attributes->set('authorized_by_admin_id', $admin->id);
                $request->attributes->set('authorized_by_admin', $admin);

                return null;
            }
        }

        RateLimiter::hit($throttleKey, 300);

        return response()->json([
            'message' => 'El PIN de administrador ingresado es incorrecto.',
            'error_code' => 'INVALID_ADMIN_PIN',
        ], 403);
    }
}
