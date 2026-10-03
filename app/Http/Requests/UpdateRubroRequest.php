<?php

namespace App\Http\Requests;

use App\Models\Rubro;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRubroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rubro = $this->route('rubro');
        $rubroId = $rubro instanceof Rubro ? $rubro->id : $rubro;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('rubros', 'name')->ignore($rubroId),
            ],
        ];
    }
}
