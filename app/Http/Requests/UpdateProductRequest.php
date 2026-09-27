<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('product') ? $this->route('product')->id : null;

        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('products')->ignore($productId)->whereNull('deleted_at'),
            ],
            'barcode' => [
                'nullable',
                'string',
                Rule::unique('products')->ignore($productId)->whereNull('deleted_at'),
            ],
            'cost_price' => 'numeric|min:0',
            'selling_price' => 'numeric|min:0|gte:cost_price',
            'price_wholesale' => 'nullable|numeric|min:0',
            'price_card' => 'nullable|numeric|min:0',
            'stock' => 'numeric',
            'min_stock' => 'nullable|numeric|min:0',
            'active' => 'boolean',
            'is_sold_by_weight' => 'boolean',
            'unit_type' => 'sometimes|in:un,kg,lt,g',
            'vencimiento_dias' => 'nullable|integer|min:1|max:3650',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'is_combo' => 'boolean',
            'combo_ingredients' => 'nullable|array|required_if:is_combo,true',
            'combo_ingredients.*.id' => 'required_with:combo_ingredients|exists:products,id',
            'combo_ingredients.*.quantity' => 'required_with:combo_ingredients|numeric|min:0.001',
            'price_tiers' => 'nullable|array',
            'price_tiers.*.min_quantity' => 'required_with:price_tiers|numeric|min:1',
            'price_tiers.*.unit_price' => 'required_with:price_tiers|numeric|min:0',
            'add_stock' => 'nullable|numeric|min:0.001',
            'internal_code' => 'nullable|string',
        ];
    }
}
