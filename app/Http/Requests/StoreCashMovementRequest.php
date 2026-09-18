<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\CashShift;
use App\Models\ThirdPartyCheck;

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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:expense,withdrawal,deposit'],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['required_if:category,Otros', 'nullable', 'string', 'max:500'],
            'receipt_number' => ['nullable', 'string', 'max:100'],
            
            // Proveedor
            'supplier_id' => [
                'nullable', 
                'integer',
                'exists:suppliers,id', 
                'required_if:category,Pago a Proveedor,Reembolso de Proveedor'
            ],
            
            // Array de pagos (para pagos mixtos)
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payments.*.payment_method' => ['required', 'string', 'in:cash,transfer,check'],
            
            // Validación específica si el pago incluye cheque
            'payments.*.check_id' => [
                'nullable',
                'integer',
                'required_if:payments.*.payment_method,check',
                // El cheque debe existir y estar in_wallet
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $check = ThirdPartyCheck::find($value);
                        if (!$check) {
                            $fail('El cheque seleccionado no existe.');
                        } elseif ($check->status !== 'in_wallet') {
                            $fail('El cheque seleccionado ya no se encuentra en cartera.');
                        }
                    }
                }
            ],
        ];
    }
}
