<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\EcommerceIntegration;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEcommerceIntegrationRequest extends FormRequest
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
        $integration = $this->route('integration');
        $integrationId = $integration instanceof EcommerceIntegration ? $integration->id : $integration;
        $currentPlatform = $integration instanceof EcommerceIntegration ? $integration->platform : null;
        $targetPlatform = $this->input('platform', $currentPlatform);

        return [
            'platform' => [
                'sometimes',
                'string',
                Rule::in(EcommerceIntegration::PLATFORMS),
            ],
            'name' => [
                'sometimes',
                'string',
                'max:100',
            ],
            'store_id' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('ecommerce_integrations')
                    ->where(function ($query) use ($targetPlatform) {
                        return $query->where('platform', $targetPlatform);
                    })
                    ->ignore($integrationId),
            ],
            'base_url' => [
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
