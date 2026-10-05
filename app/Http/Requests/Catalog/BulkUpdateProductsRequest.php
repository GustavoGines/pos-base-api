<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpdateProductsRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'integer|exists:products,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->whereNull('deleted_at'),
            ],
            'active' => 'nullable|boolean',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $fields = ['category_id', 'brand_id', 'supplier_id', 'active'];
            $hasUpdateField = false;

            foreach ($fields as $field) {
                if ($this->has($field) || array_key_exists($field, $this->all())) {
                    $hasUpdateField = true;
                    break;
                }
            }

            if (! $hasUpdateField) {
                $validator->errors()->add('update_fields', 'No update parameters provided.');
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_ids.required' => 'Debe seleccionar al menos un producto.',
            'product_ids.array' => 'El listado de productos debe ser un arreglo.',
            'product_ids.min' => 'Debe seleccionar al menos un producto.',
            'product_ids.*.integer' => 'El identificador de producto debe ser un entero.',
            'product_ids.*.exists' => 'Uno o más productos seleccionados no existen.',
            'category_id.exists' => 'La categoría seleccionada no existe.',
            'brand_id.exists' => 'La marca seleccionada no existe.',
            'supplier_id.exists' => 'El proveedor seleccionado no existe o fue eliminado.',
            'active.boolean' => 'El estado activo debe ser verdadero o falso.',
        ];
    }
}
