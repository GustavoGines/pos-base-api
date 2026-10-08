<?php

namespace App\DTOs;

class ProcessSaleDTO
{
    public function __construct(
        public readonly float $total,
        public readonly float $totalSurcharge,
        public readonly float $shippingCost,
        public readonly ?float $tenderedAmount,
        public readonly ?float $changeAmount,
        public readonly string $status,
        public readonly array $payments,
        public readonly array $items,
        public readonly ?array $checkDetails,
        public readonly bool $requiresDispatch,
        public readonly string $fulfillmentStatus,
        public readonly float $iibbPerceptionAmount = 0.0,
        public readonly ?float $iibbPerceptionRate = null
    ) {}

    public static function fromArray(array $validated): self
    {
        return new self(
            total: (float) $validated['total'],
            totalSurcharge: (float) ($validated['total_surcharge'] ?? 0),
            shippingCost: (float) ($validated['shipping_cost'] ?? 0),
            tenderedAmount: isset($validated['tendered_amount']) ? (float) $validated['tendered_amount'] : null,
            changeAmount: isset($validated['change_amount']) ? (float) $validated['change_amount'] : null,
            status: $validated['status'] ?? 'completed',
            payments: $validated['payments'] ?? [],
            items: $validated['items'] ?? [],
            checkDetails: $validated['check_details'] ?? null,
            requiresDispatch: (bool) ($validated['requires_dispatch'] ?? false),
            fulfillmentStatus: $validated['fulfillment_status'] ?? 'pending',
            iibbPerceptionAmount: (float) ($validated['iibb_perception_amount'] ?? 0),
            iibbPerceptionRate: isset($validated['iibb_perception_rate']) && $validated['iibb_perception_rate'] !== null
                ? (float) $validated['iibb_perception_rate']
                : null
        );
    }
}
