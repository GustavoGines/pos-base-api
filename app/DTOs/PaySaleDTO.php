<?php

namespace App\DTOs;

class PaySaleDTO
{
    public function __construct(
        public readonly array $payments,
        public readonly float $totalSurcharge,
        public readonly float $shippingCost,
        public readonly ?float $tenderedAmount,
        public readonly ?float $changeAmount,
        public readonly array $items,
        public readonly ?array $checkDetails
    ) {}

    public static function fromRequest(array $validated): self
    {
        return new self(
            payments: $validated['payments'] ?? [],
            totalSurcharge: (float) ($validated['total_surcharge'] ?? 0),
            shippingCost: (float) ($validated['shipping_cost'] ?? 0),
            tenderedAmount: isset($validated['tendered_amount']) ? (float) $validated['tendered_amount'] : null,
            changeAmount: isset($validated['change_amount']) ? (float) $validated['change_amount'] : null,
            items: $validated['items'] ?? [],
            checkDetails: $validated['check_details'] ?? null
        );
    }
}
