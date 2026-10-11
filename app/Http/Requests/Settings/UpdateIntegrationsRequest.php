<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIntegrationsRequest extends FormRequest
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
            'mp_qr_enabled' => 'sometimes',
            'mp_point_device_id' => 'nullable|string|max:100',
            'mp_access_token' => 'nullable|string',
            'mp_webhook_secret' => 'nullable|string',
            'afip_enabled' => 'sometimes',
            'afip_cuit' => 'nullable|string|max:20',
            'afip_pto_vta' => 'nullable|integer|min:1|max:99999',
            'afip_environment' => 'nullable|in:testing,production',
        ];
    }
}
