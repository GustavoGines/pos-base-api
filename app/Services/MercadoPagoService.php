<?php

namespace App\Services;

use App\Events\PaymentApprovedEvent;
use App\Models\BusinessSetting;
use App\Models\MpTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MercadoPagoService
{
    protected ?string $accessToken;
    protected ?string $webhookSecret;
    protected ?string $collectorId;

    public function __construct(
        ?string $accessToken = null,
        ?string $webhookSecret = null,
        ?string $collectorId = null
    ) {
        $this->accessToken = $accessToken;
        $this->webhookSecret = $webhookSecret;
        $this->collectorId = $collectorId;
    }

    /**
     * Resolve the active MP Access Token (constructor override or encrypted secret).
     */
    public function getAccessToken(): ?string
    {
        return $this->accessToken ?: BusinessSetting::getSecret('mp_access_token');
    }

    /**
     * Resolve the active MP Webhook Secret (constructor override or encrypted secret).
     */
    public function getWebhookSecret(): ?string
    {
        return $this->webhookSecret ?: BusinessSetting::getSecret('mp_webhook_secret');
    }

    /**
     * Resolve the active MP Collector ID.
     */
    public function getCollectorId(): ?string
    {
        return $this->collectorId ?: BusinessSetting::where('key', 'mp_collector_id')->value('value');
    }

    /**
     * Build modern Orders API (v1/orders) payload with Rounding/Discount Drift Reconciliation.
     */
    public function buildV1OrderPayload(
        string $externalPosId,
        string $externalRef,
        float $amount,
        array $items,
        ?string $notificationUrl = null
    ): array {
        $roundedTotal = round($amount, 2);
        $runningSum = 0.0;
        $mappedItems = [];

        foreach ($items as $item) {
            $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);
            $qty = (int) ($item['quantity'] ?? 1);
            $lineTotal = round($unitPrice * $qty, 2);
            $runningSum += $lineTotal;

            $mappedItem = [
                'title' => $item['title'] ?? 'Producto',
                'unit_price' => $unitPrice,
                'quantity' => $qty,
                'total_amount' => $lineTotal,
            ];

            if (! empty($item['sku'])) {
                $mappedItem['sku_number'] = $item['sku'];
            } elseif (! empty($item['sku_number'])) {
                $mappedItem['sku_number'] = $item['sku_number'];
            }

            $mappedItems[] = $mappedItem;
        }

        $runningSum = round($runningSum, 2);
        $diff = round($roundedTotal - $runningSum, 2);

        // Strict Invariant: Automatic rounding drift / discount reconciliation
        // Ensures sum(items.total_amount) === total_amount
        if (abs($diff) >= 0.01) {
            $mappedItems[] = [
                'sku_number' => 'DISC-ADJ',
                'category' => 'marketplace',
                'title' => ($diff < 0) ? 'Descuento Global / Ajuste' : 'Recargo / Ajuste Redondeo',
                'description' => 'Ajuste de centavos o descuento global',
                'unit_price' => $diff,
                'quantity' => 1,
                'unit_measure' => 'unit',
                'total_amount' => $diff,
            ];
        }

        $payload = [
            'type' => 'qr',
            'external_reference' => $externalRef,
            'total_amount' => $roundedTotal,
            'description' => "Venta POS #{$externalRef}",
            'items' => $mappedItems,
            'config' => [
                'qr' => [
                    'external_pos_id' => $externalPosId,
                    'mode' => 'hybrid',
                ],
            ],
        ];

        if (!empty($notificationUrl) && str_starts_with(strtolower($notificationUrl), 'https://')) {
            $payload['notification_url'] = $notificationUrl;
        }

        return $payload;
    }

    /**
     * Build legacy Instore QR payload.
     */
    public function buildInstoreQrPayload(
        string $externalRef,
        float $amount,
        array $items,
        string $notificationUrl
    ): array {
        $roundedTotal = round($amount, 2);
        $runningSum = 0.0;
        $mappedItems = [];

        foreach ($items as $item) {
            $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);
            $qty = (int) ($item['quantity'] ?? 1);
            $lineTotal = round($unitPrice * $qty, 2);
            $runningSum += $lineTotal;

            $mappedItems[] = [
                'sku_number' => $item['sku'] ?? $item['sku_number'] ?? 'PROD-001',
                'category' => 'marketplace',
                'title' => $item['title'] ?? 'Producto',
                'description' => $item['title'] ?? 'Producto',
                'unit_price' => $unitPrice,
                'quantity' => $qty,
                'unit_measure' => 'unit',
                'total_amount' => $lineTotal,
            ];
        }

        $diff = round($roundedTotal - $runningSum, 2);

        // Strict Invariant: sum(items.total_amount) === total_amount
        if (abs($diff) >= 0.01 || empty($mappedItems)) {
            $mappedItems[] = [
                'sku_number' => 'DISC-ADJ',
                'category' => 'marketplace',
                'title' => ($diff < 0) ? 'Descuento Global / Ajuste' : (($diff > 0) ? 'Recargo / Ajuste Redondeo' : 'Cobro Genérico'),
                'description' => 'Ajuste de centavos o descuento global',
                'unit_price' => empty($mappedItems) ? $roundedTotal : $diff,
                'quantity' => 1,
                'unit_measure' => 'unit',
                'total_amount' => empty($mappedItems) ? $roundedTotal : $diff,
            ];
        }

        $payload = [
            'external_reference' => $externalRef,
            'title' => "Cobro Mostrador {$externalRef}",
            'description' => 'Sistema POS Mostrador',
            'total_amount' => $roundedTotal,
            'items' => $mappedItems,
        ];

        // Mercado Pago strict requirement: Webhooks must be HTTPS and publicly accessible.
        // If we send a local http:// URL, MP throws preference_creation_error (400).
        if (!empty($notificationUrl) && str_starts_with(strtolower($notificationUrl), 'https://')) {
            $payload['notification_url'] = $notificationUrl;
        }

        return $payload;
    }

    /**
     * Create an in-store order in Mercado Pago and persist transaction.
     */
    public function createInStoreOrder(
        string $posId,
        string $externalRef,
        float $amount,
        array $items = [],
        ?string $notificationUrl = null
    ): array {
        $token = $this->getAccessToken();
        $notifUrl = $notificationUrl ?: url('/api/webhooks/mercadopago');
        $userId = $this->getCollectorId();
        $payload = $this->buildInstoreQrPayload($externalRef, $amount, $items, $notifUrl);

        $response = Http::withToken($token)
            ->withHeaders(['X-Idempotency-Key' => $externalRef])
            ->timeout(20)
            ->put("https://api.mercadopago.com/instore/orders/qr/seller/collectors/{$userId}/pos/{$posId}/qrs", $payload);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Mercado Pago API error ({$response->status()}): " . $response->body()
            );
        }

        $data = $response->json();
        $orderId = (string) ($data['id'] ?? $data['order_id'] ?? '');
        $qrData = $data['qr_data'] ?? $data['point_of_interaction']['transaction_data']['qr_code'] ?? null;

        $tx = MpTransaction::updateOrCreate(
            ['external_reference' => $externalRef],
            [
                'collector_id' => $this->getCollectorId(),
                'pos_id' => $posId,
                'amount' => round($amount, 2),
                'status' => MpTransaction::STATUS_OPENED,
                'mp_order_id' => $orderId,
                'qr_data' => $qrData,
                'payload_received' => $data,
                'user_id' => auth()->id(),
            ]
        );

        return [
            'success' => true,
            'external_reference' => $externalRef,
            'order_id' => $orderId,
            'qr_data' => $qrData,
            'transaction' => $tx,
        ];
    }

    /**
     * Create Point payment intent for physical Posnet device.
     */
    public function createPointPaymentIntent(
        string $deviceId,
        string $externalRef,
        float $amount,
        string $description = ''
    ): array {
        $token = $this->getAccessToken();

        $body = [
            'amount' => (int) round($amount * 100),
            'description' => $description ?: "Venta POS #{$externalRef}",
            'additional_info' => [
                'external_reference' => $externalRef,
            ],
        ];

        $response = Http::withToken($token)
            ->withHeaders(['X-Idempotency-Key' => $externalRef])
            ->timeout(20)
            ->post("https://api.mercadopago.com/point/integration-api/devices/{$deviceId}/payment-intents", $body);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Mercado Pago Point API error ({$response->status()}): " . $response->body()
            );
        }

        $data = $response->json();
        $paymentIntentId = (string) ($data['id'] ?? $data['payment_intent_id'] ?? '');
        $status = $data['status'] ?? 'open';

        $tx = MpTransaction::updateOrCreate(
            ['external_reference' => $externalRef],
            [
                'pos_id' => $deviceId,
                'amount' => round($amount, 2),
                'status' => MpTransaction::STATUS_OPENED,
                'mp_order_id' => $paymentIntentId,
                'payload_received' => $data,
                'user_id' => auth()->id(),
            ]
        );

        return [
            'success' => true,
            'external_reference' => $externalRef,
            'payment_intent_id' => $paymentIntentId,
            'status' => $status,
            'transaction' => $tx,
        ];
    }

    /**
     * Query order or intent status from local database and Mercado Pago.
     */
    public function checkOrderStatus(string $orderOrIntentId, ?string $externalRef = null): array
    {
        $tx = MpTransaction::where(function ($q) use ($orderOrIntentId, $externalRef) {
            if ($externalRef) {
                $q->where('external_reference', $externalRef);
            } else {
                $q->where('external_reference', $orderOrIntentId)
                  ->orWhere('mp_order_id', $orderOrIntentId);
            }
        })->first();

        // If already approved locally, return immediately
        if ($tx && $tx->status === MpTransaction::STATUS_APPROVED) {
            return [
                'status' => 'approved',
                'external_reference' => $tx->external_reference,
                'mp_payment_id' => $tx->mp_payment_id,
                'mp_order_id' => $tx->mp_order_id,
                'transaction' => $tx,
            ];
        }

        $token = $this->getAccessToken();
        $isApproved = false;
        $mpPaymentId = null;
        $status = $tx?->status ?? 'pending';

        if ($token) {
            try {
                $data = null;
                $isSuccessful = false;

                // 1. Search by external_reference (QR orders)
                $extRefToSearch = $externalRef ?? $tx?->external_reference ?? $orderOrIntentId;
                if ($extRefToSearch) {
                    $response = Http::withToken($token)
                        ->timeout(15)
                        ->get("https://api.mercadopago.com/merchant_orders/search?external_reference={$extRefToSearch}");

                    if ($response->successful() && !empty($response->json()['elements'])) {
                        $data = $response->json()['elements'][0];
                        $isSuccessful = true;
                    }
                }

                // 2. Try v1/orders (if order ID is numeric)
                if (!$isSuccessful && is_numeric($orderOrIntentId)) {
                    $response = Http::withToken($token)
                        ->timeout(15)
                        ->get("https://api.mercadopago.com/v1/orders/{$orderOrIntentId}");
                    
                    if ($response->successful()) {
                        $data = $response->json();
                        $isSuccessful = true;
                    }
                }

                // 3. Try Point intents (if uuid)
                if (!$isSuccessful && !is_numeric($orderOrIntentId)) {
                    $response = Http::withToken($token)
                        ->timeout(15)
                        ->get("https://api.mercadopago.com/point/integration-api/payment-intents/{$orderOrIntentId}");
                    
                    if ($response->successful()) {
                        $data = $response->json();
                        $isSuccessful = true;
                    }
                }

                if ($isSuccessful && is_array($data)) {
                    if (! empty($data['payments']) && is_array($data['payments'])) {
                        foreach ($data['payments'] as $payment) {
                            if (($payment['status'] ?? '') === 'approved') {
                                $isApproved = true;
                                $mpPaymentId = (string) ($payment['id'] ?? '');
                                break;
                            }
                        }
                    }

                    if (! $isApproved && ! empty($data['payment']) && is_array($data['payment'])) {
                        if (($data['payment']['status'] ?? '') === 'approved') {
                            $isApproved = true;
                            $mpPaymentId = (string) ($data['payment']['id'] ?? '');
                        }
                    }

                    if (! $isApproved && in_array(strtolower($data['status'] ?? ''), ['approved', 'closed', 'processed'])) {
                        $isApproved = true;
                        $mpPaymentId = (string) ($data['payment_id'] ?? $data['payments'][0]['id'] ?? '');
                    }

                    if ($isApproved) {
                        $status = 'approved';
                        if ($tx) {
                            $tx->update([
                                'status' => MpTransaction::STATUS_APPROVED,
                                'mp_payment_id' => $mpPaymentId ?: $tx->mp_payment_id,
                                'payload_received' => $data,
                            ]);

                            event(new PaymentApprovedEvent(
                                externalReference: $tx->external_reference,
                                mpPaymentId: $tx->mp_payment_id,
                                amount: (float) $tx->amount,
                                posId: $tx->pos_id,
                                transaction: $tx
                            ));
                        }
                    } else {
                        $status = strtolower($data['status'] ?? 'pending');
                    }
                }
            } catch (\Throwable $e) {
                // Fallback to local transaction status
            }
        }

        return [
            'status' => $status,
            'external_reference' => $tx?->external_reference ?? $externalRef ?? $orderOrIntentId,
            'mp_payment_id' => $mpPaymentId ?? $tx?->mp_payment_id,
            'mp_order_id' => $orderOrIntentId,
            'transaction' => $tx,
        ];
    }

    /**
     * Cancel an active in-store order in Mercado Pago and update transaction.
     */
    public function cancelInStoreOrder(string $externalRef): bool
    {
        $tx = MpTransaction::where('external_reference', $externalRef)->first();

        if ($tx && $tx->mp_order_id && $this->getAccessToken()) {
            try {
                Http::withToken($this->getAccessToken())
                    ->timeout(10)
                    ->post("https://api.mercadopago.com/v1/orders/{$tx->mp_order_id}/cancel");
            } catch (\Throwable $e) {
                // Continue cancellation locally even if MP network call fails
            }
        }

        if ($tx) {
            $tx->update(['status' => MpTransaction::STATUS_CANCELLED]);
        }

        return true;
    }

    /**
     * Verify Webhook HMAC SHA256 Signature with Replay Attack Protection & Whitespace Tolerance.
     */
    public function verifyWebhookSignature(
        string|Request $xSignatureHeader,
        ?string $xRequestIdHeader = null,
        ?string $dataId = null
    ): bool {
        if ($xSignatureHeader instanceof Request) {
            $request = $xSignatureHeader;
            $sigHeader = $request->header('x-signature') ?? $request->header('X-Signature') ?? '';
            $reqId = $request->header('x-request-id') ?? $request->header('X-Request-Id') ?? '';
            $dId = (string) ($request->input('data.id') ?? $request->input('id') ?? $request->query('data.id') ?? $request->query('id') ?? '');
        } else {
            $sigHeader = $xSignatureHeader;
            $reqId = $xRequestIdHeader ?? '';
            $dId = (string) ($dataId ?? '');
        }

        $ts = null;
        $hash = null;
        $parts = explode(',', $sigHeader);

        foreach ($parts as $part) {
            $sub = explode('=', trim($part), 2);
            if (count($sub) === 2) {
                $key = strtolower(trim($sub[0]));
                $val = trim($sub[1]);
                if ($key === 'ts') {
                    $ts = $val;
                }
                if ($key === 'v1') {
                    $hash = $val;
                }
            }
        }

        if (! $ts || ! $hash) {
            return false;
        }

        // Replay attack mitigation: reject webhooks outside the 300-second (5 min) freshness window
        $maxTolerance = 300;
        if (abs(time() - (int) $ts) > $maxTolerance) {
            return false;
        }

        $secret = $this->getWebhookSecret();
        if (! $secret) {
            return false;
        }

        $manifest = "id:{$dId};request-id:{$reqId};ts:{$ts};";
        $computedHash = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($computedHash, $hash);
    }
}
