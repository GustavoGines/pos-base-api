<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProductRequest extends FormRequest
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

        if ($this->boolean('is_combo')) {
            $this->merge(['cost_price' => 0]);
        } elseif ($this->has('selling_price') && ! $this->has('cost_price')) {
            // CP-07: En actualizaciones parciales que sólo envían selling_price, inyectar el cost_price actual
            // para que la regla gte:cost_price tenga contra qué comparar sin fallar por campo ausente.
            $routeProduct = $this->route('product');
            if ($routeProduct instanceof Product) {
                $this->merge(['cost_price' => (float) $routeProduct->cost_price]);
            }
        }
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
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => 'nullable|exists:brands,id',
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'is_combo' => 'boolean',
            'combo_ingredients' => 'nullable|array|required_if:is_combo,true',
            'combo_ingredients.*.id' => 'required_with:combo_ingredients|exists:products,id',
            'combo_ingredients.*.quantity' => 'required_with:combo_ingredients|numeric|min:0.001',
            'price_tiers' => 'nullable|array',
            'price_tiers.*.min_quantity' => 'required_with:price_tiers|numeric|min:1',
            'price_tiers.*.unit_price' => 'required_with:price_tiers|numeric|min:0',
            'add_stock' => 'nullable|numeric|min:0.001',
            'internal_code' => [
                'nullable',
                'string',
                Rule::unique('products', 'internal_code')->ignore($productId)->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del producto es obligatorio.',
            'name.unique' => 'Ya existe otro producto registrado con este nombre.',
            'barcode.unique' => 'Este código de barras (EAN) ya está asignado a otro producto.',
            'internal_code.unique' => 'Este código interno (PLU) ya está asignado a otro producto.',
            'selling_price.gte' => 'El precio de venta no puede ser menor al precio de costo.',
            'combo_ingredients.required_if' => 'Debes añadir al menos un producto incluido en el Combo / Receta.',
            'combo_ingredients.*.id.exists' => 'Uno de los productos seleccionados para el combo no existe.',
            'combo_ingredients.*.quantity.min' => 'La cantidad de cada producto del combo debe ser mayor a 0.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->boolean('is_combo') || ! is_array($this->input('combo_ingredients'))) {
                return;
            }

            $ingredients = $this->input('combo_ingredients');
            $ids = array_values(array_filter(array_column($ingredients, 'id')));
            if (empty($ids)) {
                return;
            }

            $products = Product::whereIn('id', $ids)->get()->keyBy('id');
            foreach ($ingredients as $ing) {
                $childId = $ing['id'] ?? null;
                $reqQty = (float) ($ing['quantity'] ?? 0);
                if (! $childId || $reqQty <= 0 || ! isset($products[$childId])) {
                    continue;
                }

                $child = $products[$childId];
                $availStock = (float) $child->stock;
                if ($reqQty > $availStock) {
                    $reqFmt = rtrim(rtrim(number_format($reqQty, 3, '.', ''), '0'), '.');
                    $availFmt = rtrim(rtrim(number_format($availStock, 3, '.', ''), '0'), '.');
                    $validator->errors()->add(
                        'combo_ingredients',
                        "La cantidad requerida de «{$child->name}» ({$reqFmt}) supera su stock disponible actual ({$availFmt})."
                    );
                }
            }
        });
    }
}
