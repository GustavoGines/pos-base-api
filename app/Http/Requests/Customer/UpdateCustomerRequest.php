<?php

namespace App\Http\Requests\Customer;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
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
        $customerId = $this->route('customer')?->id ?? $this->route('customer');

        return [
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'document_number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('customers')->ignore($customerId)->whereNull('deleted_at'),
            ],
            'credit_limit' => 'nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
            'default_price_tier' => 'nullable|string|in:base,wholesale,card',
            'delivery_address' => 'nullable|string|max:500',
            'is_internal_account' => 'sometimes|boolean',
            'document_type' => [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) {
                    if ($this->input('applies_iibb_perception') && $value != 80) {
                        $fail('Para aplicar percepciones IIBB el cliente debe tener tipo de documento CUIT (80).');
                    }
                },
            ],
            'tax_condition' => 'nullable|string|max:50',
            'fiscal_address' => 'nullable|string|max:500',
            'applies_iibb_perception' => 'nullable|boolean',
            'iibb_perception_rate' => 'nullable|numeric|min:0|max:100',
        ];
    }

    /**
     * Custom message for validation errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del cliente es obligatorio.',
            'document_number.required' => 'El número de documento es obligatorio.',
            'document_number.unique' => 'Ya existe otro cliente con ese número de documento (DNI/RUT).',
        ];
    }
}
