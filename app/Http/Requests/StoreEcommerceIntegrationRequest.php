<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\EcommerceIntegration;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEcommerceIntegrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'platform' => [
                'required',
                'string',
                Rule::in(EcommerceIntegration::PLATFORMS),
            ],
            'name' => [
                'required',
                'string',
                'max:100',
            ],
            'store_id' => [
                'required_if:platform,tiendanube',
                'nullable',
                'string',
                'max:100',
                Rule::unique('ecommerce_integrations')->where(function ($query) {
                    return $query->where('platform', $this->input('platform'));
                }),
            ],
            'base_url' => [
                'required_if:platform,woocommerce',
                'nullable',
                'url',
                'max:255',
            ],
            'client_id' => [
                'nullable',
                'string',
                'max:150',
            ],
            'client_secret' => [
                'nullable',
                'string',
            ],
            'access_token' => [
                'nullable',
                'string',
            ],
            'refresh_token' => [
                'nullable',
                'string',
            ],
            'token_expires_at' => [
                'nullable',
                'date',
            ],
            'webhook_secret' => [
                'nullable',
                'string',
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
            'sync_stock_enabled' => [
                'sometimes',
                'boolean',
            ],
            'sync_price_enabled' => [
                'sometimes',
                'boolean',
            ],
            'auto_pause_on_zero_stock' => [
                'sometimes',
                'boolean',
            ],
            'rate_limit_per_minute' => [
                'sometimes',
                'integer',
                'min:1',
                'max:600',
            ],
            'batch_size' => [
                'sometimes',
                'integer',
                'min:1',
                'max:200',
            ],
            'timeout_seconds' => [
                'sometimes',
                'integer',
                'min:5',
                'max:120',
            ],
            'settings' => [
                'nullable',
                'array',
            ],
        ];
    }
}
