<?php

namespace App\DTOs;

use App\Models\Customer;

class SaleContextDTO
{
    public function __construct(
        public readonly ?int $userId,
        public readonly ?int $cashShiftId,
        public readonly ?int $customerId,
        public readonly ?int $quoteId,
        public readonly ?string $priceList,
        public readonly ?string $deliveryAddress,
        public readonly bool $isInternalAccount,
        public readonly ?int $authorizedByAdminId = null,
        public readonly ?string $voidReason = null
    ) {}

    /**
     * Build from array data (usually from FormRequest validated data)
     */
    public static function fromArray(array $data, ?int $authenticatedUserId = null, bool $isInternalAccount = false, ?int $authorizedByAdminId = null): self
    {
        if (isset($data['customer_id']) && ! $isInternalAccount) {
            $isInternalAccount = (bool) Customer::where('id', $data['customer_id'])->value('is_internal_account');
        }

        return new self(
            userId: $data['user_id'] ?? $authenticatedUserId,
            cashShiftId: $data['cash_shift_id'] ?? null,
            customerId: $data['customer_id'] ?? null,
            quoteId: $data['quote_id'] ?? null,
            priceList: $data['price_list'] ?? null,
            deliveryAddress: $data['delivery_address'] ?? null,
            isInternalAccount: $isInternalAccount,
            authorizedByAdminId: $authorizedByAdminId,
            voidReason: $data['void_reason'] ?? null
        );
    }
}
