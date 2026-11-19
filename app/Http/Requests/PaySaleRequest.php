<?php

namespace App\Http\Requests;

use App\Models\CashShift;
use App\Models\PaymentMethod;
use App\Models\Sale;
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
            'payments.*.mp_payment_id' => 'nullable|string|max:50',
            'payments.*.mp_order_id' => 'nullable|string|max:50',
            'payments.*.reference_id' => 'nullable|string|max:100',
            'payments.*.transfer_account_alias' => 'nullable|string|max:100',
            'payments.*.transfer_sender_name' => 'nullable|string|max:150',
            'total_surcharge' => 'required|numeric|min:0',
            'shipping_cost' => 'nullable|numeric|min:0',
            'iibb_perception_amount' => 'nullable|numeric|min:0',
            'iibb_perception_rate' => 'nullable|numeric|min:0|max:100',
            'tendered_amount' => 'nullable|numeric|min:0',
            'change_amount' => 'nullable|numeric|min:0',
            'items' => 'nullable|array',
            'items.*.product_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],
            'items.*.batch_id' => \App\Services\BatchStockService::hasLotTrackingSchema()
                ? 'nullable|integer|exists:product_batches,id'
                : 'nullable|integer',
            'items.*.is_temporary' => 'nullable|boolean',
            'items.*.save_to_catalog' => 'nullable|boolean',
            'items.*.barcode' => 'nullable|string|max:255',
            'items.*.name' => 'nullable|string|max:255',
            'items.*.product_name' => 'nullable|string|max:255',
            'items.*.custom_description' => 'nullable|string|max:255',
            'items.*.custom_details' => 'nullable|string|max:1000',
            'items.*.cost_price' => 'nullable|numeric|min:0',
            'items.*.custom_cost_price' => 'nullable|numeric|min:0',
            'items.*.iva_rate' => 'nullable|numeric|in:0,10.5,21,27',
            'items.*.is_sold_by_weight' => 'nullable|boolean',
            'items.*.is_combo' => 'nullable|boolean',
            'items.*.is_service' => 'nullable|boolean',
            'items.*.combo_ingredients' => 'nullable|array',
            'items.*.combo_ingredients.*.id' => [
                'required_with:items.*.combo_ingredients',
                'integer',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],
            'items.*.combo_ingredients.*.quantity' => 'required_with:items.*.combo_ingredients|numeric|min:0.001',
            'items.*.quantity' => 'required_with:items|numeric|min:0.001',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.subtotal' => 'required_with:items|numeric|min:0',
            'user_id' => 'nullable|integer|exists:users,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
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

            // Autorización de descuento puntual (F1-CRIT-01)
            'discount_pin' => 'nullable|string|size:4|regex:/^[0-9]{4}$/',
            'admin_pin' => 'nullable|string|min:4|max:10',
            'client_transaction_id' => 'nullable|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'cash_shift_id.required' => 'Debe especificar el turno de caja activo para registrar el cobro.',
            'cash_shift_id.exists' => 'El turno de caja especificado ya está cerrado o no existe.',
            'items.*.product_id.exists' => 'Uno de los productos en el carrito ya no está disponible en la base de datos.',
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                $items = $this->input('items');
                if (is_array($items)) {
                    foreach ($items as $idx => $item) {
                        $hasProductId = ! empty($item['product_id']);
                        $isTemporary = filter_var($item['is_temporary'] ?? false, FILTER_VALIDATE_BOOLEAN);
                        $saveToCatalog = filter_var($item['save_to_catalog'] ?? false, FILTER_VALIDATE_BOOLEAN);

                        if (! $hasProductId && ! $isTemporary && ! $saveToCatalog) {
                            $validator->errors()->add(
                                "items.{$idx}.product_id",
                                'Debe especificar un producto del catálogo o marcar el ítem como temporal / creación rápida.'
                            );
                        }

                        if (! $hasProductId && ($isTemporary || $saveToCatalog)) {
                            $name = trim((string) ($item['name'] ?? $item['product_name'] ?? $item['custom_description'] ?? ''));
                            if ($name === '') {
                                $validator->errors()->add(
                                    "items.{$idx}.name",
                                    'La descripción o nombre es obligatoria para productos temporales o rápidos.'
                                );
                            }
                        }
                    }
                }

                // Fix F1-MED-06: Impedir que el cobro de una venta en espera se impute a un turno cerrado
                $explicitShiftId = $this->input('cash_shift_id');
                if ($explicitShiftId === null) {
                    $routeSale = $this->route('sale');
                    $saleModel = $routeSale instanceof Sale ? $routeSale : ($routeSale ? Sale::find($routeSale) : null);
                    $fallbackShiftId = $saleModel?->cash_shift_id;

                    $isFallbackOpen = $fallbackShiftId !== null && CashShift::where('id', $fallbackShiftId)
                        ->where('status', 'open')
                        ->exists();

                    if (! $isFallbackOpen) {
                        $validator->errors()->add(
                            'cash_shift_id',
                            'El turno de caja original de esta venta ya fue cerrado. Debe especificar el turno de caja activo actual.'
                        );
                    }
                }

                $payments = $this->input('payments', []);
                if (is_array($payments) && ! empty($payments)) {
                    $paymentMethodIds = collect($payments)->pluck('payment_method_id')->filter()->unique();
                    $hasCheque = PaymentMethod::whereIn('id', $paymentMethodIds)
                        ->where('code', 'cheque')
                        ->exists();

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
