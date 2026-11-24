<?php

namespace App\Http\Requests;

use App\Models\Rubro;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRubroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:rubros,name'],
            'description' => ['nullable', 'string', 'max:255'],
            'vertical_type' => ['nullable', 'string', Rule::in(Rubro::VALID_VERTICAL_TYPES)],
        ];
    }
}
