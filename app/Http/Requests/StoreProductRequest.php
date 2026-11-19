<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('internal_code')) {
            $this->merge([
                'internal_code' => str_pad(trim((string) $this->input('internal_code')), 5, '0', STR_PAD_LEFT),
            ]);
        }

        if ($this->boolean('is_combo') || ! $this->filled('cost_price')) {
            $this->merge(['cost_price' => 0]);
        }

        if ($this->boolean('is_service')) {
            $this->merge([
                'is_combo' => false,
                'is_sold_by_weight' => false,
                'stock' => $this->filled('stock') ? $this->input('stock') : 0,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('products')->whereNull('deleted_at'),
            ],
            'barcode' => [
                'nullable',
                'string',
                Rule::unique('products')->whereNull('deleted_at'),
            ],
            'cost_price' => 'nullable|numeric|min:0',
            'selling_price' => [
                'numeric',
                'min:0',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $cost = is_numeric($this->input('cost_price')) ? (float) $this->input('cost_price') : 0.0;
                    if ($cost > 0 && is_numeric($value) && (float) $value < $cost) {
                        $fail('El precio de venta no puede ser menor al precio de costo.');
                    }
                },
            ],
            'price_wholesale' => 'nullable|numeric|min:0',
            'price_card' => 'nullable|numeric|min:0',
            'iva_rate' => ['nullable', 'numeric', 'in:0,10.5,21,27'],
            'stock' => 'numeric',
            'min_stock' => 'nullable|numeric|min:0',
            'active' => 'boolean',
            'needs_catalog_review' => 'boolean',
            'is_sold_by_weight' => 'boolean',
            'unit_type' => 'sometimes|in:un,kg,lt,g',
            'vencimiento_dias' => 'nullable|integer|min:1|max:3650',
            'expiration_date' => 'nullable|date',
            'expiry_alert_days' => 'nullable|integer|min:1|max:365',
            'track_batches' => 'boolean',
            'lot_deduction_policy' => 'sometimes|in:fefo,fifo',
            'allow_sell_expired' => 'boolean',
            'has_production_recipe' => 'boolean',
            'recipe_yield_quantity' => 'nullable|numeric|min:0.001',
            'recipe_ingredients' => 'nullable|array',
            'recipe_ingredients.*.id' => 'required_with:recipe_ingredients|integer|exists:products,id',
            'recipe_ingredients.*.quantity' => 'required_with:recipe_ingredients|numeric|min:0.001',
            'recipe_ingredients.*.waste_pct' => 'nullable|numeric|min:0|max:95',
            'initial_batch_code' => 'nullable|string|max:64',
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => 'nullable|exists:brands,id',
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'is_combo' => 'boolean',
            'is_service' => 'boolean',
            'combo_ingredients' => 'nullable|array|required_if:is_combo,true',
            'combo_ingredients.*.id' => 'required_with:combo_ingredients|exists:products,id',
            'combo_ingredients.*.quantity' => 'required_with:combo_ingredients|numeric|min:0.001',
            'price_tiers' => 'nullable|array',
            'price_tiers.*.min_quantity' => 'required_with:price_tiers|numeric|min:1',
            'price_tiers.*.unit_price' => 'required_with:price_tiers|numeric|min:0',
            'internal_code' => [
                'nullable',
                'string',
                Rule::unique('products', 'internal_code')->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del producto es obligatorio.',
            'name.unique' => 'Ya existe un producto registrado con este nombre.',
            'barcode.unique' => 'Este código de barras (EAN) ya está asignado a otro producto.',
            'internal_code.unique' => 'Este código interno (PLU) ya está asignado a otro producto.',
            'selling_price.gte' => 'El precio de venta no puede ser menor al precio de costo.',
            'combo_ingredients.required_if' => 'Debes añadir al menos un producto incluido en el Combo / Receta.',
            'combo_ingredients.*.id.exists' => 'Uno de los productos seleccionados para el combo no existe.',
            'combo_ingredients.*.quantity.min' => 'La cantidad de cada producto del combo debe ser mayor a 0.',
        ];
    }
}
