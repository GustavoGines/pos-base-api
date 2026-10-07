<?php

namespace App\Http\Controllers\Api;

use App\Events\PaymentApprovedEvent;
use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\MpTransaction;
use App\Models\MpWebhook;
use App\Services\MercadoPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MercadoPagoController extends Controller
{
    public function __construct(
        protected MercadoPagoService $mpService
    ) {
    }

    /**
     * Create an in-store QR order in Mercado Pago.
     * POST /api/pos/mp/create-order
     */
    public function createOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pos_id' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'items' => 'nullable|array',
            'external_reference' => 'nullable|string',
        ]);

        $externalRef = $validated['external_reference'] ?? ('POS-' . strtoupper(Str::random(12)));
        $items = $validated['items'] ?? [];

        try {
            $result = $this->mpService->createInStoreOrder(
                posId: strtoupper(str_replace('-', '', $validated['pos_id'])),
                externalRef: $externalRef,
                amount: (float) $validated['amount'],
                items: $items
            );

            return response()->json([
                'success' => true,
                'external_reference' => $result['external_reference'],
                'qr_data' => $result['qr_data'],
                'order_id' => $result['order_id'],
                'transaction' => $result['transaction'],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear orden en Mercado Pago: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Create a Point payment intent for physical Posnet.
     * POST /api/pos/mp/create-point-intent
     */
    public function createPointIntent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string',
            'device_id' => 'nullable|string',
            'external_reference' => 'nullable|string',
        ]);

        $deviceId = $validated['device_id'] ?? BusinessSetting::where('key', 'mp_point_device_id')->value('value');

        if (empty($deviceId)) {
            return response()->json([
                'message' => 'Dispositivo Point no configurado.',
                'errors' => [
                    'device_id' => ['Dispositivo Point no configurado en el sistema.'],
                ],
            ], 422);
        }

        $externalRef = $validated['external_reference'] ?? ('POS-' . strtoupper(Str::random(12)));
        $description = $validated['description'] ?? "Venta POS #{$externalRef}";

        try {
            $result = $this->mpService->createPointPaymentIntent(
                deviceId: $deviceId,
                externalRef: $externalRef,
                amount: (float) $validated['amount'],
                description: $description
            );

            return response()->json([
                'success' => true,
                'external_reference' => $result['external_reference'],
                'payment_intent_id' => $result['payment_intent_id'],
                'status' => $result['status'],
                'transaction' => $result['transaction'],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al enviar pago al Posnet: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Check status of a Mercado Pago transaction (polling fallback).
     * GET /api/pos/mp/status/{external_reference}
     */
    public function status(Request $request, string $externalReference): JsonResponse
    {
        $tx = MpTransaction::where('external_reference', $externalReference)->first();

        if (! $tx) {
            return response()->json([
                'message' => 'Transacción no encontrada',
            ], 404);
        }

        if ($tx->status === MpTransaction::STATUS_APPROVED) {
            return response()->json([
                'success' => true,
                'external_reference' => $tx->external_reference,
                'status' => 'approved',
                'mp_payment_id' => $tx->mp_payment_id,
                'mp_order_id' => $tx->mp_order_id,
                'amount' => (float) $tx->amount,
            ]);
        }

        // Query MP API to check if state changed
        $orderOrIntentId = $tx->mp_order_id ?: $tx->external_reference;
        $this->mpService->checkOrderStatus($orderOrIntentId, $externalReference);

        $tx->refresh();

        return response()->json([
            'success' => true,
            'external_reference' => $tx->external_reference,
            'status' => $tx->status,
            'mp_payment_id' => $tx->mp_payment_id,
            'mp_order_id' => $tx->mp_order_id,
            'amount' => (float) $tx->amount,
        ]);
    }

    /**
     * Alias for status method.
     */
    public function checkStatus(Request $request, string $externalReference): JsonResponse
    {
        return $this->status($request, $externalReference);
    }

    /**
     * Cancel an active in-store order.
     * POST /api/pos/mp/cancel-order
     */
    public function cancelOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'external_reference' => 'required|string',
        ]);

        $this->mpService->cancelInStoreOrder($validated['external_reference']);

        return response()->json([
            'success' => true,
            'message' => 'Orden cancelada exitosamente',
        ]);
    }

    /**
     * Public Webhook endpoint for Mercado Pago notifications.
     * POST /api/webhooks/mercadopago
     */
    public function webhook(Request $request): JsonResponse
    {
        $dataId = (string) (
            $request->input('data.id')
            ?? $request->input('id')
            ?? $request->query('data.id')
            ?? $request->query('id')
            ?? ''
        );
        $topic = (string) ($request->input('type') ?? $request->input('topic') ?? $request->query('topic') ?? 'payment');
        $action = (string) ($request->input('action') ?? 'payment.updated');

        // 1. Log webhook
        $webhookLog = MpWebhook::create([
            'action' => $action,
            'topic' => $topic,
            'resource_id' => $dataId,
            'payload' => $request->all(),
            'is_processed' => false,
        ]);

        // 2. Verify signature
        $sigHeader = $request->header('x-signature') ?? $request->header('X-Signature') ?? '';
        $reqIdHeader = $request->header('x-request-id') ?? $request->header('X-Request-Id') ?? '';

        if (! $this->mpService->verifyWebhookSignature($sigHeader, $reqIdHeader, $dataId)) {
            $webhookLog->update(['processing_error' => 'Invalid signature']);
            return response()->json(['error' => 'Invalid webhook signature'], 403);
        }

        // 3. Process payment status
        $payload = $request->all();
        $status = $payload['status'] ?? $payload['data']['status'] ?? null;
        $externalRef = $payload['external_reference'] ?? $payload['data']['external_reference'] ?? null;
        $amount = $payload['transaction_amount'] ?? $payload['amount'] ?? null;
        $mpPaymentId = $payload['id'] ?? $payload['data']['id'] ?? $dataId;

        if ($status !== 'approved' && ! empty($dataId) && $this->mpService->getAccessToken()) {
            try {
                $paymentResp = Http::withToken($this->mpService->getAccessToken())
                    ->timeout(15)
                    ->get("https://api.mercadopago.com/v1/payments/{$dataId}");

                if ($paymentResp->successful()) {
                    $paymentData = $paymentResp->json();
                    $status = $paymentData['status'] ?? $status;
                    $externalRef = $paymentData['external_reference'] ?? $externalRef;
                    $amount = $paymentData['transaction_amount'] ?? $amount;
                    $mpPaymentId = (string) ($paymentData['id'] ?? $mpPaymentId);
                }
            } catch (\Throwable $e) {
                // Ignore API fetch error and proceed with available data
            }
        }

        if ($status === 'approved') {
            $tx = null;
            if (! empty($externalRef)) {
                $tx = MpTransaction::where('external_reference', $externalRef)->first();
            }
            if (! $tx && ! empty($mpPaymentId)) {
                $tx = MpTransaction::where('mp_payment_id', $mpPaymentId)->first();
            }

            if (! $tx && ! empty($externalRef)) {
                $tx = MpTransaction::create([
                    'external_reference' => $externalRef,
                    'amount' => (float) ($amount ?? 0),
                    'status' => MpTransaction::STATUS_APPROVED,
                    'mp_payment_id' => $mpPaymentId,
                    'payload_received' => $payload,
                ]);
            } elseif ($tx) {
                $tx->update([
                    'status' => MpTransaction::STATUS_APPROVED,
                    'mp_payment_id' => $mpPaymentId ?: $tx->mp_payment_id,
                    'payload_received' => $payload,
                ]);
            }

            $webhookLog->update([
                'is_processed' => true,
                'processed_at' => now(),
            ]);

            event(new PaymentApprovedEvent(
                externalReference: $externalRef ?? $tx?->external_reference ?? '',
                mpPaymentId: (string) ($mpPaymentId ?? $tx?->mp_payment_id),
                amount: (float) ($amount ?? $tx?->amount ?? 0),
                posId: $tx?->pos_id,
                transaction: $tx
            ));
        }

        return response()->json(['status' => 'ok']);
    }
}
