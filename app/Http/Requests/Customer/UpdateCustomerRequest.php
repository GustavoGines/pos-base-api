<?php

namespace App\Http\Requests\Customer;

use App\Models\Customer;
use App\Services\Afip\AfipHelper;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCustomerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function resolveTargetCustomer(): ?Customer
    {
        $routeParam = $this->route('customer');
        if ($routeParam instanceof Customer) {
            return $routeParam;
        }

        return $routeParam !== null ? Customer::find($routeParam) : null;
    }

    /**
     * Normaliza updates parciales sin pisar un documento existente y asegura coherencia de document_type.
     */
    protected function prepareForValidation(): void
    {
        $customer = $this->resolveTargetCustomer();
        $input = $this->all();
        $merged = [];

        $hasDocKey = array_key_exists('document_number', $input);
        if ($hasDocKey) {
            $rawDoc = $input['document_number'];
            if ($rawDoc === null) {
                $merged['document_number'] = null;
            } else {
                $cleaned = preg_replace('/[\s.\-]/', '', trim((string) $rawDoc));
                $merged['document_number'] = $cleaned === '' ? null : $cleaned;
            }
        }

        $hasTaxCondKey = array_key_exists('tax_condition', $input);
        if ($hasTaxCondKey) {
            $rawTaxCond = $input['tax_condition'];
            $merged['tax_condition'] = ($rawTaxCond === null || trim((string) $rawTaxCond) === '')
                ? 'consumidor_final'
                : strtolower(trim((string) $rawTaxCond));
        }

        $effectiveDoc = $hasDocKey ? $merged['document_number'] : $customer?->document_number;
        $effectiveTaxCond = $hasTaxCondKey
            ? $merged['tax_condition']
            : ($customer?->tax_condition ?? 'consumidor_final');
        $effectiveAppliesIibb = array_key_exists('applies_iibb_perception', $input)
            ? filter_var($input['applies_iibb_perception'], FILTER_VALIDATE_BOOLEAN)
            : (bool) ($customer?->applies_iibb_perception ?? false);
        $requiresCuit = ($effectiveTaxCond !== 'consumidor_final') || $effectiveAppliesIibb;

        $hasDocTypeKey = array_key_exists('document_type', $input) && $input['document_type'] !== null && $input['document_type'] !== '';
        $isDocEmpty = $effectiveDoc === null || trim((string) $effectiveDoc) === '';

        if ($isDocEmpty) {
            if (! $requiresCuit && ($hasDocKey || $hasDocTypeKey || ($customer && (int) $customer->document_type !== AfipHelper::DOC_CONSUMIDOR_FINAL))) {
                $merged['document_type'] = AfipHelper::DOC_CONSUMIDOR_FINAL;
            }
        } else {
            $digits = preg_replace('/\D/', '', (string) $effectiveDoc);
            $currentOrIncomingType = $hasDocTypeKey
                ? (int) $input['document_type']
                : (int) ($customer?->document_type ?? AfipHelper::DOC_CONSUMIDOR_FINAL);

            if ($currentOrIncomingType === AfipHelper::DOC_CONSUMIDOR_FINAL) {
                $merged['document_type'] = ($requiresCuit || strlen($digits) === 11)
                    ? AfipHelper::DOC_CUIT
                    : AfipHelper::DOC_DNI;
            } elseif (! $hasDocTypeKey && $requiresCuit && strlen($digits) === 11) {
                $merged['document_type'] = AfipHelper::DOC_CUIT;
            }
        }

        if (! empty($merged)) {
            $this->merge($merged);
        }
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
                'nullable',
                'string',
                'max:50',
                Rule::unique('customers', 'document_number')->ignore($customerId)->whereNull('deleted_at'),
            ],
            'credit_limit' => 'nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
            'default_price_tier' => 'nullable|string|in:base,wholesale,card',
            'delivery_address' => 'nullable|string|max:500',
            'is_internal_account' => 'sometimes|boolean',
            'document_type' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::in([
                    AfipHelper::DOC_CUIT,
                    AfipHelper::DOC_CUIL,
                    AfipHelper::DOC_DNI,
                    AfipHelper::DOC_CONSUMIDOR_FINAL,
                ]),
            ],
            'tax_condition' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['consumidor_final', 'responsable_inscripto', 'monotributo', 'exento']),
            ],
            'fiscal_address' => 'nullable|string|max:500',
            'applies_iibb_perception' => 'nullable|boolean',
            'iibb_perception_rate' => 'nullable|numeric|min:0|max:100',
        ];
    }

    /**
     * Evalúa obligatoriedad de CUIT y coherencia de document_type contra el estado efectivo resultante.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $customer = $this->resolveTargetCustomer();
            $hasDocKey = $this->has('document_number');
            $hasDocTypeKey = $this->has('document_type') && $this->input('document_type') !== null && $this->input('document_type') !== '';

            $effectiveDoc = $hasDocKey
                ? $this->input('document_number')
                : $customer?->document_number;
            $effectiveTaxCond = $this->has('tax_condition')
                ? (string) $this->input('tax_condition')
                : (string) ($customer?->tax_condition ?? 'consumidor_final');
            $effectiveAppliesIibb = $this->has('applies_iibb_perception')
                ? filter_var($this->input('applies_iibb_perception'), FILTER_VALIDATE_BOOLEAN)
                : (bool) ($customer?->applies_iibb_perception ?? false);
            $effectiveDocType = $hasDocTypeKey
                ? (int) $this->input('document_type')
                : (int) ($customer?->document_type ?? AfipHelper::DOC_CONSUMIDOR_FINAL);

            $requiresCuit = ($effectiveTaxCond !== 'consumidor_final') || $effectiveAppliesIibb;
            $isDocEmpty = $effectiveDoc === null || trim((string) $effectiveDoc) === '';

            if ($requiresCuit) {
                if ($isDocEmpty || ! preg_match('/^\d{11}$/', (string) $effectiveDoc)) {
                    $v->errors()->add(
                        'document_number',
                        'Para la condición fiscal seleccionada o percepciones IIBB es obligatorio ingresar un CUIT de 11 dígitos numéricos.'
                    );
                }
                if ($effectiveDocType !== AfipHelper::DOC_CUIT) {
                    $v->errors()->add(
                        'document_type',
                        'Para la condición fiscal seleccionada o percepciones IIBB el cliente debe tener tipo de documento CUIT (80).'
                    );
                }

                return;
            }

            if ($isDocEmpty) {
                return;
            }

            if ($effectiveDocType === AfipHelper::DOC_CONSUMIDOR_FINAL) {
                $v->errors()->add(
                    'document_type',
                    'El tipo de documento 99 (Sin Documento) no puede utilizarse con un número de documento cargado.'
                );
            } elseif ($hasDocKey || $hasDocTypeKey) {
                if (in_array($effectiveDocType, [AfipHelper::DOC_CUIT, AfipHelper::DOC_CUIL], true)) {
                    if (! preg_match('/^\d{11}$/', (string) $effectiveDoc)) {
                        $v->errors()->add(
                            'document_number',
                            'El CUIT/CUIL debe contener exactamente 11 dígitos numéricos.'
                        );
                    }
                } elseif ($effectiveDocType === AfipHelper::DOC_DNI) {
                    if (! preg_match('/^\d{7,8}$/', (string) $effectiveDoc)) {
                        $v->errors()->add(
                            'document_number',
                            'El DNI debe contener entre 7 y 8 dígitos numéricos.'
                        );
                    }
                }
            }
        });
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
            'document_number.unique' => 'Ya existe otro cliente con ese número de documento (DNI/CUIT).',
            'tax_condition.in' => 'La condición frente al IVA seleccionada no es válida.',
        ];
    }
}
