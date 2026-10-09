<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaySaleRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            'payments' => 'required|array|min:1',
            'payments.*.payment_method_id' => 'required|integer|exists:payment_methods,id',
            'payments.*.base_amount' => 'required|numeric|min:0',
            'payments.*.surcharge_amount' => 'required|numeric|min:0',
            'payments.*.total_amount' => 'required|numeric|min:0',
            'total_surcharge' => 'required|numeric|min:0',
            'shipping_cost' => 'nullable|numeric|min:0',
            'iibb_perception_amount' => 'nullable|numeric|min:0',
            'iibb_perception_rate' => 'nullable|numeric|min:0|max:100',
            'tendered_amount' => 'nullable|numeric|min:0',
            'change_amount' => 'nullable|numeric|min:0',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|integer|exists:products,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.001',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.subtotal' => 'required_with:items|numeric|min:0',
            'user_id' => 'nullable|integer|exists:users,id',
            'cash_shift_id' => [
                'nullable',
                'integer',
                Rule::exists('cash_shifts', 'id')->where('status', 'open'),
            ],
            // Check details
            'check_details' => 'nullable|array',
            'check_details.bank_name' => 'nullable|string|max:100',
            'check_details.check_number' => 'nullable|string|max:50',
            'check_details.issue_date' => 'nullable|date',
            'check_details.payment_date' => 'nullable|date',
            'check_details.issuer_name' => 'nullable|string|max:100',
            'check_details.issuer_cuit' => 'nullable|string|max:20',
            'check_details.*.bank_name' => 'nullable|string|max:100',
            'check_details.*.check_number' => 'nullable|string|max:50',
            'check_details.*.issue_date' => 'nullable|date',
            'check_details.*.payment_date' => 'nullable|date',
            'check_details.*.issuer_name' => 'nullable|string|max:100',
            'check_details.*.issuer_cuit' => 'nullable|string|max:20',
        ];
    }

    public function messages(): array
    {
        return [
            'cash_shift_id.exists' => 'El turno de caja especificado ya está cerrado o no existe.',
        ];
    }
}
