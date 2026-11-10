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
            'tarjeta' => 'card',
            'card' => 'card',
            'credito' => 'card',
            'debito' => 'card',
            'cheque' => 'check',
            'check' => 'check',
            'fondos_propios' => 'owner_funds',
            'bolsillo' => 'owner_funds',
            'owner_funds' => 'owner_funds',
        ];

        $sourceMap = [
            'cash_register' => 'cash_register',
            'caja' => 'cash_register',
            'register' => 'cash_register',
            'owner_funds' => 'owner_funds',
            'fondos_propios' => 'owner_funds',
            'bolsillo' => 'owner_funds',
            'pocket' => 'owner_funds',
        ];

        $rawDefaultSource = strtolower(trim((string) $this->input('funding_source', 'cash_register')));
        $defaultSource = $sourceMap[$rawDefaultSource] ?? 'cash_register';

        if (! $this->has('payments') && $this->has('amount')) {
            $rawMethod = strtolower(trim((string) $this->input('payment_method', 'cash')));
            $normalizedMethod = $methodMap[$rawMethod] ?? $rawMethod;
            $fundingSource = $defaultSource;

            if ($normalizedMethod === 'owner_funds') {
                $normalizedMethod = 'cash';
                $fundingSource = 'owner_funds';
            } elseif ($normalizedMethod === 'check') {
                $fundingSource = 'cash_register';
            }

            $singlePayment = [
                'amount' => $this->input('amount'),
                'payment_method' => $normalizedMethod,
                'funding_source' => $fundingSource,
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
                if (is_array($payment)) {
                    $rawMethod = strtolower(trim((string) ($payment['payment_method'] ?? 'cash')));
                    $normalizedMethod = $methodMap[$rawMethod] ?? $rawMethod;

                    $rawSource = strtolower(trim((string) ($payment['funding_source'] ?? $defaultSource)));
                    $normalizedSource = $sourceMap[$rawSource] ?? $rawSource;

                    if ($normalizedMethod === 'owner_funds') {
                        $normalizedMethod = 'cash';
                        $normalizedSource = 'owner_funds';
                    } elseif ($normalizedMethod === 'check') {
                        $normalizedSource = 'cash_register';
                    }

                    $payment['payment_method'] = $normalizedMethod;
                    $payment['funding_source'] = $normalizedSource;
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
                'in:cash,transfer,check,card',
                function ($attribute, $value, $fail) {
                    if ($value === 'check' && $this->input('type') !== 'supplier_payment') {
                        $fail('El método de pago con cheque solo está disponible para pagos a proveedores (supplier_payment).');
                    }
                },
            ],
            'payments.*.funding_source' => [
                'nullable',
                'string',
                'in:cash_register,owner_funds',
                function ($attribute, $value, $fail) {
                    if ($value === 'owner_funds' && ! in_array($this->input('type'), ['supplier_payment', 'expense'], true)) {
                        $fail('El origen Fondos Propios / Bolsillo solo está disponible para pagos a proveedores o gastos operativos.');
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
