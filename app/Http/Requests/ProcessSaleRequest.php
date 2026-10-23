<?php

namespace App\Http\Requests;

use App\Models\PaymentMethod;
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
            'total' => 'required|numeric|min:0',
            'total_surcharge' => 'required|numeric|min:0',
            'shipping_cost' => 'nullable|numeric|min:0',
            'iibb_perception_amount' => 'nullable|numeric|min:0',
            'iibb_perception_rate' => 'nullable|numeric|min:0|max:100',
            'payments' => 'exclude_if:status,pending|required|array|min:1',
            'payments.*.payment_method_id' => 'required|integer|exists:payment_methods,id',
            'payments.*.base_amount' => 'required|numeric|min:0',
            'payments.*.surcharge_amount' => 'required|numeric|min:0',
            'payments.*.total_amount' => 'required|numeric|min:0',
            'payments.*.mp_payment_id' => 'nullable|string|max:50',
            'payments.*.mp_order_id' => 'nullable|string|max:50',
            'payments.*.reference_id' => 'nullable|string|max:100',
            'tendered_amount' => 'nullable|numeric|min:0',
            'change_amount' => 'nullable|numeric|min:0',
            'cash_shift_id' => [
                'required',
                'integer',
                Rule::exists('cash_shifts', 'id')->where('status', 'open'),
            ],
            'user_id' => 'nullable|integer|exists:users,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'delivery_address' => 'nullable|string|max:500',
            'status' => 'nullable|string|in:pending,completed',
            'items' => 'required|array|min:1',
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.subtotal' => 'required|numeric|min:0',
            'quote_id' => 'nullable|integer|exists:quotes,id',
            'price_list' => 'nullable|string|max:100',
            // Check details bridge validation
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

            // Remitos y Envios
            'requires_dispatch' => 'nullable|boolean',
            'fulfillment_status' => 'nullable|string|in:pending,delivered,cancelled',

            // Autorización de descuento puntual
            'discount_pin' => 'nullable|string|size:4|regex:/^[0-9]{4}$/',
            'admin_pin' => 'nullable|string|min:4|max:10',

            // Clave de idempotencia ante reintentos de red (F3-RESIL-01)
            'client_transaction_id' => 'nullable|string|max:64',
        ];
    }

    public function messages(): array
    {
        return [
            'cash_shift_id.exists' => 'El turno de caja ya fue cerrado. Por favor, recargue la aplicación.',
            'customer_id.exists' => 'El cliente seleccionado no existe en el sistema.',
            'user_id.exists' => 'El cajero actual no está registrado en el sistema. Inicie sesión nuevamente.',
            'items.*.product_id.exists' => 'Uno de los productos en el carrito ya no está disponible en la base de datos.',
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                if ($this->input('status') === 'pending') {
                    return;
                }

                $payments = $this->input('payments', []);
                if (is_array($payments) && ! empty($payments)) {
                    $paymentMethodIds = collect($payments)->pluck('payment_method_id')->filter()->unique();
                    $methodsByCode = PaymentMethod::whereIn('id', $paymentMethodIds)
                        ->pluck('code', 'id');

                    $hasCuentaCorriente = $methodsByCode->contains('cuenta_corriente');
                    if ($hasCuentaCorriente && empty($this->input('customer_id'))) {
                        $validator->errors()->add('customer_id', 'Debe seleccionar un cliente para ventas en Cuenta Corriente.');
                    }

                    $hasCheque = $methodsByCode->contains('cheque');
                    if ($hasCheque) {
                        $checkDetails = $this->input('check_details');
                        if (empty($checkDetails) || ! is_array($checkDetails)) {
                            $validator->errors()->add('check_details', 'Debe completar los datos del cheque (banco, número y firmante).');
                        } else {
                            $checksToValidate = isset($checkDetails[0]) && is_array($checkDetails[0])
                                ? $checkDetails
                                : [$checkDetails];

                            foreach ($checksToValidate as $idx => $check) {
                                if (
                                    empty(trim((string) ($check['bank_name'] ?? ''))) ||
                                    empty(trim((string) ($check['check_number'] ?? ''))) ||
                                    empty(trim((string) ($check['issuer_name'] ?? '')))
                                ) {
                                    $validator->errors()->add(
                                        "check_details.{$idx}",
                                        'Debe completar los datos obligatorios del cheque (banco, número y firmante).'
                                    );
                                    break;
                                }
                            }
                        }
                    }
                }
            },
        ];
    }
}
