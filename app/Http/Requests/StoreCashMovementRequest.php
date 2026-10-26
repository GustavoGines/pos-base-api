<?php

namespace App\Http\Requests;

use App\Models\ThirdPartyCheck;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCashMovementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Autenticación se maneja en el middleware
    }

    /**
     * REP-01: Retrocompatibilidad con terminales v1.8.5 que envían estructura plana
     * {"type": "expense", "amount": 250.00, "payment_method": "cash"} sin array 'payments'.
     */
    protected function prepareForValidation(): void
    {
        $methodMap = [
            'efectivo' => 'cash',
            'cash' => 'cash',
            'transferencia' => 'transfer',
            'transfer' => 'transfer',
            'cheque' => 'check',
            'check' => 'check',
        ];

        if (! $this->has('payments') && $this->has('amount')) {
            $rawMethod = strtolower(trim((string) $this->input('payment_method', 'cash')));
            $normalizedMethod = $methodMap[$rawMethod] ?? $rawMethod;

            $singlePayment = [
                'amount' => $this->input('amount'),
                'payment_method' => $normalizedMethod,
            ];
            if ($this->filled('check_id')) {
                $singlePayment['check_id'] = $this->input('check_id');
            }

            $this->merge([
                'payments' => [$singlePayment],
            ]);

            return;
        }

        if (is_array($this->input('payments'))) {
            $normalizedPayments = [];
            foreach ($this->input('payments') as $payment) {
                if (is_array($payment) && isset($payment['payment_method'])) {
                    $rawMethod = strtolower(trim((string) $payment['payment_method']));
                    $payment['payment_method'] = $methodMap[$rawMethod] ?? $rawMethod;
                }
                $normalizedPayments[] = $payment;
            }
            $this->merge(['payments' => $normalizedPayments]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cash_shift_id' => ['nullable', 'integer'],
            'cash_register_id' => ['nullable', 'integer', 'exists:cash_registers,id'],
            'type' => ['required', 'string', 'in:expense,withdrawal,deposit,supplier_payment'],
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'receipt_number' => ['nullable', 'string', 'max:100'],
            'receipt_file_url' => ['nullable', 'string', 'max:255'],
            'admin_pin' => ['nullable', 'string', 'min:4', 'max:10'],

            // Proveedor
            'supplier_id' => [
                'nullable',
                'integer',
                'exists:suppliers,id',
                'required_if:type,supplier_payment',
                'prohibited_if:type,expense',
            ],

            // Array de pagos (para pagos mixtos)
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.amount' => [
                'required',
                'numeric',
                'min:0.01',
                function ($attribute, $value, $fail) {
                    $segments = explode('.', $attribute);
                    $index = $segments[1] ?? null;
                    if ($index !== null) {
                        $payment = $this->input("payments.{$index}") ?? ($this->input('payments')[$index] ?? null);
                        if (($payment['payment_method'] ?? null) === 'check' && ! empty($payment['check_id'])) {
                            $check = ThirdPartyCheck::find($payment['check_id']);
                            if ($check && abs((float) $check->amount - (float) $value) > 0.009) {
                                $fail("El monto (\${$value}) no coincide con el valor nominal del cheque (\${$check->amount}).");
                            }
                        }
                    }
                },
            ],
            'payments.*.payment_method' => [
                'required',
                'string',
                'in:cash,transfer,check',
                function ($attribute, $value, $fail) {
                    if ($value === 'check' && $this->input('type') !== 'supplier_payment') {
                        $fail('El método de pago con cheque solo está disponible para pagos a proveedores (supplier_payment).');
                    }
                },
            ],

            // Validación específica si el pago incluye cheque
            'payments.*.check_id' => [
                'nullable',
                'integer',
                'distinct',
                'required_if:payments.*.payment_method,check',
                // El cheque debe existir y estar in_wallet
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $check = ThirdPartyCheck::find($value);
                        if (! $check) {
                            $fail('El cheque seleccionado no existe.');
                        } elseif ($check->status !== 'in_wallet') {
                            $fail('El cheque seleccionado ya no se encuentra en cartera.');
                        }
                    }
                },
            ],
        ];
    }
}
