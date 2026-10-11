<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundEcommerceWebhookJob;
use App\Models\EcommerceIntegration;
use App\Models\EcommerceWebhook;
use App\Services\Ecommerce\EcommerceManager;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class EcommerceWebhookController extends Controller
{
    public function __construct(
        protected EcommerceManager $ecommerceManager
    ) {}

    /**
     * Handle inbound webhook for Mercado Libre.
     */
    public function mercadolibre(Request $request): JsonResponse
    {
        return $this->handleWebhook(EcommerceIntegration::PLATFORM_MERCADOLIBRE, $request);
    }

    /**
     * Handle inbound webhook for Tienda Nube.
     */
    public function tiendanube(Request $request): JsonResponse
    {
        return $this->handleWebhook(EcommerceIntegration::PLATFORM_TIENDANUBE, $request);
    }

    /**
     * Handle inbound webhook for WooCommerce.
     */
    public function woocommerce(Request $request): JsonResponse
    {
        return $this->handleWebhook(EcommerceIntegration::PLATFORM_WOOCOMMERCE, $request);
    }

    /**
     * Unified handler for external ecommerce webhooks.
     */
    protected function handleWebhook(string $platform, Request $request): JsonResponse
    {
        // 1. Anti-Replay Check (< 300s tolerance window)
        $timestamp = $this->extractTimestamp($request);
        if ($timestamp !== null && abs(now()->timestamp - $timestamp) > 300) {
            return response()->json([
                'error' => 'Timestamp out of acceptable window (> 300s)',
            ], 400);
        }

        // 2. Resolve Active Integration
        $integration = EcommerceIntegration::where('platform', $platform)
            ->where('is_active', true)
            ->first();

        if (! $integration) {
            return response()->json([
                'status' => 'ignored',
                'reason' => 'no_active_integration',
            ], 200);
        }

        // 3. Cryptographic Signature / Zero-Trust Verification
        $adapter = $this->ecommerceManager->forIntegration($integration);
        if (! $adapter->verifyWebhookSignature($request)) {
            return response()->json([
                'error' => 'Invalid webhook signature',
            ], 401);
        }

        // 4. Parse Webhook Event Data
        $parsed = $adapter->parseWebhook($request);
        $eventId = ! empty($parsed['external_event_id']) ? (string) $parsed['external_event_id'] : (string) Str::uuid();

        // 5. Anti-Echo Check
        $orderId = $parsed['external_order_id'] ?? null;
        if (
            Cache::has("ecommerce_echo:{$platform}:{$eventId}") ||
            ($orderId !== null && Cache::has("ecommerce_echo:{$platform}:{$orderId}"))
        ) {
            return response()->json([
                'status' => 'ignored',
                'reason' => 'echo_suppressed',
            ], 200);
        }

        // 6. Idempotent Ingestion in Database
        try {
            $eventDateTime = $timestamp !== null ? Carbon::createFromTimestamp($timestamp) : now();

            $webhook = EcommerceWebhook::create([
                'integration_id' => $integration->id,
                'platform' => $platform,
                'event_type' => $parsed['event_type'] ?? 'order',
                'external_event_id' => $eventId,
                'event_timestamp' => $eventDateTime,
                'payload' => $request->all(),
                'headers' => $request->headers->all(),
                'status' => 'received',
            ]);
        } catch (UniqueConstraintViolationException|QueryException $e) {
            // Handle unique constraint duplicate idempotently with HTTP 200
            return response()->json([
                'status' => 'ignored',
                'reason' => 'duplicate_idempotent',
            ], 200);
        }

        // 7. Dispatch Asynchronous Inbound Job to dedicated queue
        ProcessInboundEcommerceWebhookJob::dispatch($webhook->id)->onQueue('ecommerce-inbound');

        // 8. Immediate HTTP 200 response (< 50ms)
        return response()->json([
            'status' => 'accepted',
            'webhook_id' => $webhook->id,
        ], 200);
    }

    /**
     * Extract event timestamp from headers or payload for replay detection.
     */
    protected function extractTimestamp(Request $request): ?int
    {
        // Check platform specific headers
        if ($request->hasHeader('x-wc-webhook-timestamp')) {
            return (int) $request->header('x-wc-webhook-timestamp');
        }

        if ($request->hasHeader('x-linkedstore-event-timestamp')) {
            return (int) $request->header('x-linkedstore-event-timestamp');
        }

        if ($request->hasHeader('x-signature')) {
            $sig = (string) $request->header('x-signature');
            if (preg_match('/ts=(\d+)/', $sig, $matches)) {
                return (int) $matches[1];
            }
        }

        // Check payload parameters
        $keys = ['event_timestamp', 'timestamp', 'ts', 'sent_at', 'date_created', 'created_at'];
        foreach ($keys as $key) {
            $val = $request->input($key);
            if (! empty($val)) {
                if (is_numeric($val)) {
                    return (int) $val;
                }
                $parsed = strtotime((string) $val);
                if ($parsed !== false) {
                    return $parsed;
                }
            }
        }

        return null;
    }
}
