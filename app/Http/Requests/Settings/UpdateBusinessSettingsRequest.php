<?php

namespace App\Http\Requests\Settings;

use App\Models\BusinessSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessSettingsRequest extends FormRequest
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
        return [
            'card_percentage' => 'sometimes|numeric|between:-100,100',
            'wholesale_percentage' => 'sometimes|numeric|between:-100,100',
            'tax_condition' => 'sometimes|nullable|string|max:80',
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                if ($this->has('tax_condition')) {
                    $raw = $this->input('tax_condition');
                    if ($raw !== null && trim((string) $raw) !== '' && BusinessSetting::normalizeIssuerTaxCondition($raw) === null) {
                        $validator->errors()->add(
                            'tax_condition',
                            'Seleccione una condición frente al IVA válida para el comercio emisor (IVA Responsable Inscripto, Responsable Monotributo o IVA Exento).'
                        );
                    }
                }
            },
        ];
    }
}
