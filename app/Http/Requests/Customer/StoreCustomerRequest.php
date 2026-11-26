<?php

namespace App\Http\Requests\Customer;

use App\Services\Afip\AfipHelper;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCustomerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza documento, condición fiscal y coherencia bidireccional de document_type.
     */
    protected function prepareForValidation(): void
    {
        $rawTaxCond = $this->input('tax_condition');
        $taxCondition = ($rawTaxCond === null || trim((string) $rawTaxCond) === '')
            ? 'consumidor_final'
            : strtolower(trim((string) $rawTaxCond));

        $appliesIibb = filter_var($this->input('applies_iibb_perception'), FILTER_VALIDATE_BOOLEAN);
        $requiresCuit = ($taxCondition !== 'consumidor_final') || $appliesIibb;

        $normalizedDoc = null;
        if ($this->has('document_number') && $this->input('document_number') !== null) {
            $cleaned = preg_replace('/[\s.\-]/', '', trim((string) $this->input('document_number')));
            $normalizedDoc = $cleaned === '' ? null : $cleaned;
        }

        $rawDocType = $this->input('document_type');
        $hasExplicitDocType = $this->has('document_type') && $rawDocType !== null && $rawDocType !== '';
        $docType = $hasExplicitDocType ? (int) $rawDocType : null;

        if ($normalizedDoc === null) {
            if (! $requiresCuit) {
                $docType = AfipHelper::DOC_CONSUMIDOR_FINAL;
            } elseif ($docType === null || $docType === AfipHelper::DOC_CONSUMIDOR_FINAL) {
                $docType = AfipHelper::DOC_CUIT;
            }
        } elseif ($docType === null || $docType === AfipHelper::DOC_CONSUMIDOR_FINAL) {
            $digits = preg_replace('/\D/', '', $normalizedDoc);
            $docType = ($requiresCuit || strlen($digits) === 11)
                ? AfipHelper::DOC_CUIT
                : AfipHelper::DOC_DNI;
        }

        $this->merge([
            'tax_condition' => $taxCondition,
            'document_number' => $normalizedDoc,
            'document_type' => $docType,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'document_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('customers', 'document_number')->whereNull('deleted_at'),
            ],
            'credit_limit' => 'nullable|numeric|min:0',
            'balance' => 'nullable|numeric',
            'default_price_tier' => 'nullable|string|in:base,wholesale,card',
            'delivery_address' => 'nullable|string|max:500',
            'is_internal_account' => 'nullable|boolean',
            'document_type' => [
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
     * Validación cruzada de documento vs condición frente al IVA e IIBB.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $taxCondition = (string) ($this->input('tax_condition') ?? 'consumidor_final');
            $appliesIibb = filter_var($this->input('applies_iibb_perception'), FILTER_VALIDATE_BOOLEAN);
            $requiresCuit = ($taxCondition !== 'consumidor_final') || $appliesIibb;
            $doc = $this->input('document_number');
            $docType = (int) ($this->input('document_type') ?? AfipHelper::DOC_CONSUMIDOR_FINAL);

            if ($requiresCuit) {
                if ($doc === null || ! preg_match('/^\d{11}$/', (string) $doc)) {
                    $v->errors()->add(
                        'document_number',
                        'Para la condición fiscal seleccionada o percepciones IIBB es obligatorio ingresar un CUIT de 11 dígitos numéricos.'
                    );
                }
                if ($docType !== AfipHelper::DOC_CUIT) {
                    $v->errors()->add(
                        'document_type',
                        'Para la condición fiscal seleccionada o percepciones IIBB el cliente debe tener tipo de documento CUIT (80).'
                    );
                }

                return;
            }

            if ($doc === null) {
                return;
            }

            if ($docType === AfipHelper::DOC_CONSUMIDOR_FINAL) {
                $v->errors()->add(
                    'document_type',
                    'El tipo de documento 99 (Sin Documento) no puede utilizarse con un número de documento cargado.'
                );
            } elseif (in_array($docType, [AfipHelper::DOC_CUIT, AfipHelper::DOC_CUIL], true)) {
                if (! preg_match('/^\d{11}$/', (string) $doc)) {
                    $v->errors()->add(
                        'document_number',
                        'El CUIT/CUIL debe contener exactamente 11 dígitos numéricos.'
                    );
                }
            } elseif ($docType === AfipHelper::DOC_DNI) {
                if (! preg_match('/^\d{7,8}$/', (string) $doc)) {
                    $v->errors()->add(
                        'document_number',
                        'El DNI debe contener entre 7 y 8 dígitos numéricos.'
                    );
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
            'document_number.unique' => 'Ya existe un cliente con ese número de documento (DNI/CUIT). Verificá los datos.',
            'tax_condition.in' => 'La condición frente al IVA seleccionada no es válida.',
        ];
    }
}
