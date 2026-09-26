<?php

namespace App\DTOs;

class SaleContextDTO
{
    public function __construct(
        public readonly ?int $userId,
        public readonly ?int $cashShiftId,
        public readonly ?int $customerId,
        public readonly ?int $quoteId,
        public readonly ?string $priceList,
        public readonly ?string $deliveryAddress,
        public readonly bool $isInternalAccount
    ) {}

    /**
     * Build from array data (usually from FormRequest validated data)
     */
    public static function fromArray(array $data, ?int $authenticatedUserId = null, bool $isInternalAccount = false): self
    {
        if (isset($data['customer_id']) && !$isInternalAccount) {
            $isInternalAccount = (bool) \App\Models\Customer::where('id', $data['customer_id'])->value('is_internal_account');
        }

        return new self(
            userId: $data['user_id'] ?? $authenticatedUserId,
            cashShiftId: $data['cash_shift_id'] ?? null,
            customerId: $data['customer_id'] ?? null,
            quoteId: $data['quote_id'] ?? null,
            priceList: $data['price_list'] ?? null,
            deliveryAddress: $data['delivery_address'] ?? null,
            isInternalAccount: $isInternalAccount
        );
    }
}
