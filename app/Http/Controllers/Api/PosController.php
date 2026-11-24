<?php

namespace App\Http\Controllers\Api;

use App\DTOs\ProcessSaleDTO;
use App\DTOs\SaleContextDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessSaleRequest;
use App\Models\Product;
use App\Models\ProductBatch;
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
        $hasExtended = Product::hasExtendedCatalogColumns();
        $searchCols = $hasExtended
            ? ['name', 'supplier_sku', 'technical_spec']
            : ['name'];

        $products = Product::where('active', true)
            ->where(function ($q) use ($query, $plu, $hasExtended, $searchCols) {
                $q->where('barcode', $query)
                    ->orWhere('internal_code', $query)
                    ->orWhere('internal_code', $plu)
                    ->orWhere(function ($n) use ($query, $searchCols) {
                        $n->matchingSearch($query, $searchCols);
                    });

                if ($hasExtended) {
                    $q->orWhere('supplier_sku', $query);
                }

                if (is_numeric($query)) {
                    $q->orWhere('id', $query);
                }
            })
            ->with(['children', 'priceTiers', 'category.rubro', 'brand'])
            ->get();

        return response()->json($products);
    }

    /**
     * Resuelve una etiqueta térmica dinámica de lote (`L#<batch_id>` o `L#<batch_id>#W#<gramos>`),
     * validando en vivo si esa bandeja física específica fue dada de baja o venció.
     * GET /api/pos/batches/scan?code=L#42
     */
    public function scanBatch(Request $request): JsonResponse
    {
        if (! \App\Services\BatchStockService::hasLotTrackingSchema()) {
            return response()->json(['message' => 'El módulo de lotes requiere ejecutar las migraciones pendientes.'], 503);
        }

        $code = trim((string) ($request->query('code') ?? $request->input('code') ?? ''));
        if (! preg_match('/^L#(\d+)(?:#W#(\d+(?:\.\d+)?))?$/i', $code, $matches)) {
            return response()->json(['message' => 'Código de lote inválido. Formato esperado: L#<id>.'], 422);
        }

        $batchId = (int) $matches[1];
        $weightGrams = isset($matches[2]) ? (float) $matches[2] : null;

        $batch = ProductBatch::with([
            'product.children',
            'product.priceTiers',
            'product.category.rubro',
            'product.brand',
        ])->find($batchId);

        if (! $batch || ! $batch->product || ! $batch->product->active) {
            return response()->json(['message' => 'Lote no encontrado o producto inactivo.'], 404);
        }

        $product = $batch->product;
        $isExpired = $batch->isExpired();
        $isWrittenOrVoided = in_array($batch->status, [ProductBatch::STATUS_WRITTEN_OFF, ProductBatch::STATUS_VOIDED], true);
        $isExhausted = $batch->status === ProductBatch::STATUS_EXHAUSTED || $batch->available_to_sell <= 0.0005;

        $blockReason = null;
        if ($isWrittenOrVoided) {
            $blockReason = "El lote {$batch->batch_code} fue dado de baja ({$batch->status}). Retire el producto del exhibidor.";
        } elseif ($isExpired) {
            $expStr = $batch->expiration_date?->format('d/m/Y') ?? 'fecha cumplida';
            $blockReason = "Esta bandeja (lote {$batch->batch_code}) venció el {$expStr}.";
        }

        return response()->json([
            'product' => $product,
            'batch' => [
                'id' => $batch->id,
                'batch_code' => $batch->batch_code,
                'source_type' => $batch->source_type,
                'manufactured_at' => $batch->manufactured_at?->format('Y-m-d'),
                'expiration_date' => $batch->expiration_date?->format('Y-m-d'),
                'remaining_quantity' => (float) $batch->remaining_quantity,
                'available_to_sell' => $batch->available_to_sell,
                'status' => $batch->status,
                'is_expired' => $isExpired,
            ],
            'weight_kg' => $weightGrams !== null ? round($weightGrams / 1000.0, 3) : null,
            'is_expired' => $isExpired,
            'is_written_off' => $isWrittenOrVoided,
            'is_exhausted' => $isExhausted,
            'requires_supervisor_pin' => $isExpired && ! $product->allow_sell_expired && ! $isWrittenOrVoided,
            'can_sell' => ! $isWrittenOrVoided && (! $isExpired || (bool) $product->allow_sell_expired),
            'block_reason' => $blockReason,
        ]);
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

        // 2. FINDING-CC-01: Si se envió PIN explícito (ej. para autorizar exceso de límite de crédito), resolverlo
        $pinResponse = $this->discountAuthService->resolveExplicitAdminPin($request);
        if ($pinResponse !== null) {
            return $pinResponse;
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
            'sale' => $sale->load('items.product', 'user:id,name', 'cashier:id,name', 'customer', 'payments.paymentMethod:id,name,code,is_cash', 'deliveryNote.items.product.category.rubro'),
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
