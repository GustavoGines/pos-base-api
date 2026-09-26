<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessSaleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Autenticación manejada por middleware sanctum/pin
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'total'                  => 'required|numeric|min:0',
            'total_surcharge'        => 'required|numeric|min:0',
            'shipping_cost'          => 'nullable|numeric|min:0',
            'payments'               => 'exclude_if:status,pending|required|array|min:1',
            'payments.*.payment_method_id' => 'required|integer|exists:payment_methods,id',
            'payments.*.base_amount'      => 'required|numeric|min:0',
            'payments.*.surcharge_amount' => 'required|numeric|min:0',
            'payments.*.total_amount'     => 'required|numeric|min:0',
            'tendered_amount'        => 'nullable|numeric|min:0',
            'change_amount'          => 'nullable|numeric|min:0',
            'cash_shift_id'          => [
                'required',
                'integer',
                Rule::exists('cash_shifts', 'id')->where('status', 'open'),
            ],
            'user_id'                => 'nullable|integer|exists:users,id',
            'customer_id'            => 'nullable|integer|exists:customers,id',
            'delivery_address'       => 'nullable|string|max:500',
            'status'                 => 'nullable|string|in:pending,completed',
            'items'                  => 'required|array|min:1',
            'items.*.product_id'     => 'required|integer|exists:products,id',
            'items.*.quantity'       => 'required|numeric|min:0.001',
            'items.*.unit_price'     => 'required|numeric|min:0',
            'items.*.subtotal'       => 'required|numeric|min:0',
            'quote_id'               => 'nullable|integer|exists:quotes,id',
            'price_list'             => 'nullable|string|max:100',
            // Check details bridge validation
            'check_details'          => 'nullable|array',
            'check_details.bank_name'    => 'required_with:check_details|string|max:100',
            'check_details.check_number' => 'required_with:check_details|string|max:50',
            'check_details.issue_date'   => 'required_with:check_details|date',
            'check_details.payment_date' => 'required_with:check_details|date|after_or_equal:check_details.issue_date',
            'check_details.issuer_name'  => 'required_with:check_details|string|max:100',
            'check_details.issuer_cuit'  => 'nullable|string|max:20',
            
            // Remitos y Envios
            'requires_dispatch'      => 'nullable|boolean',
            'fulfillment_status'     => 'nullable|string|in:pending,delivered,cancelled',
        ];
    }

    public function messages(): array
    {
        return [
            'cash_shift_id.exists'         => 'El turno de caja ya fue cerrado. Por favor, recargue la aplicación.',
            'customer_id.exists'           => 'El cliente seleccionado no existe en el sistema.',
            'user_id.exists'               => 'El cajero actual no está registrado en el sistema. Inicie sesión nuevamente.',
            'items.*.product_id.exists'    => 'Uno de los productos en el carrito ya no está disponible en la base de datos.',
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $payments = $this->input('payments', []);
                if (is_array($payments) && !empty($payments)) {
                    $paymentMethodIds = collect($payments)->pluck('payment_method_id')->filter()->unique();
                    $hasCuentaCorriente = \App\Models\PaymentMethod::whereIn('id', $paymentMethodIds)
                        ->where('code', 'cuenta_corriente')
                        ->exists();

                    if ($hasCuentaCorriente && empty($this->input('customer_id'))) {
                        $validator->errors()->add('customer_id', 'Debe seleccionar un cliente para ventas en Cuenta Corriente.');
                    }
                }
            }
        ];
    }
}
