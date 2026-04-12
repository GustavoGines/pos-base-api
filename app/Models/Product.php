<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'barcode', 'internal_code', 'cost_price', 'selling_price',
        'price_wholesale', 'price_card',  // [hardware_store] Listas de Precio
        'stock', 'min_stock', 'active', 'is_combo', 'is_sold_by_weight', 'sales_count', 'vencimiento_dias',
        'unit_type', 'category_id', 'brand_id', 'supplier_id'
    ];

    protected $casts = [
        'active' => 'boolean',
        'is_combo' => 'boolean',
        'is_sold_by_weight' => 'boolean',
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'price_wholesale' => 'decimal:2',  // [hardware_store]
        'price_card' => 'decimal:2',        // [hardware_store]
        'stock' => 'decimal:3',
        'min_stock' => 'double',
        'sales_count' => 'integer',
        'vencimiento_dias' => 'integer',
    ];

    /**
     * Relación de Recetas / Combos
     * Un producto COMBO está compuesto por múltiples ingredientes (niños).
     */
    public function children(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_combos', 'parent_product_id', 'child_product_id')
                    ->withPivot('quantity')
                    ->withTimestamps();
    }

    /**
     * Si este producto es un ingrediente, devuelve a qué Combos pertenece.
     */
    public function combos(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_combos', 'child_product_id', 'parent_product_id')
                    ->withPivot('quantity')
                    ->withTimestamps();
    }



    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Tramos de precio mayorista ordenados de menor a mayor.
     */
    public function priceTiers(): HasMany
    {
        return $this->hasMany(ProductPriceTier::class)->orderBy('min_quantity', 'asc');
    }

    /**
     * Motor de Precios por Volumen.
     *
     * Dado una cantidad Q, devuelve el unit_price del tramo más alto
     * cuyo min_quantity <= Q. Si no hay tramos, devuelve el precio base.
     *
     * Ejemplo con tramos: 1→$200, 50→$170, 100→$140
     *   getPriceForQuantity(1)   → $200
     *   getPriceForQuantity(50)  → $170
     *   getPriceForQuantity(75)  → $170  (tramo más alto alcanzado)
     *   getPriceForQuantity(100) → $140
     */
    public function getPriceForQuantity(float $quantity): float
    {
        $tiers = $this->relationLoaded('priceTiers')
            ? $this->priceTiers
            : $this->priceTiers()->get();

        if ($tiers->isEmpty()) {
            return (float) $this->selling_price;
        }

        // Filtramos los tramos alcanzados y tomamos el de mayor min_quantity
        $applicable = $tiers->filter(fn($t) => $quantity >= (float) $t->min_quantity);

        return $applicable->isNotEmpty()
            ? (float) $applicable->last()->unit_price
            : (float) $this->selling_price;
    }
}
