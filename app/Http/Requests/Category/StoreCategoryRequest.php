<?php

namespace App\Http\Requests\Category;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
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
        $featuresJson = DB::table('business_settings')
            ->where('key', 'license_features_dict')
            ->value('value');
        $features = $featuresJson ? json_decode($featuresJson, true) : [];
        $isPremium = ! empty($features['multi_rubro']);

        $rules = [
            'name' => ['required', 'string', 'max:255', Rule::unique('categories')],
            'description' => 'nullable|string',
        ];

        if ($isPremium) {
            $rules['rubro_id'] = ['nullable', 'integer', 'min:1', 'exists:rubros,id'];
        }

        return $rules;
    }
}
