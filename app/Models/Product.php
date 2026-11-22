<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'barcode', 'internal_code', 'cost_price', 'selling_price', 'iva_rate',
        'price_wholesale', 'price_card',  // [hardware_store] Listas de Precio
        'stock', 'min_stock', 'active', 'needs_catalog_review', 'is_combo', 'is_service', 'is_sold_by_weight', 'sales_count', 'vencimiento_dias',
        'expiration_date', 'expiry_alert_days', 'expiry_alert_snoozed_until', 'expiry_alert_last_stage',
        'track_batches', 'lot_deduction_policy', 'allow_sell_expired',
        'has_production_recipe', 'recipe_yield_quantity',
        'unit_type', 'calc_config', 'category_id', 'brand_id', 'supplier_id', 'image_path',
    ];

    protected $appends = ['image_url'];

    protected $casts = [
        'active' => 'boolean',
        'needs_catalog_review' => 'boolean',
        'is_combo' => 'boolean',
        'is_service' => 'boolean',
        'is_sold_by_weight' => 'boolean',
        'track_batches' => 'boolean',
        'allow_sell_expired' => 'boolean',
        'has_production_recipe' => 'boolean',
        'recipe_yield_quantity' => 'decimal:3',
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'iva_rate' => 'decimal:2',
        'price_wholesale' => 'decimal:2',  // [hardware_store]
        'price_card' => 'decimal:2',        // [hardware_store]
        'stock' => 'decimal:3',
        'min_stock' => 'double',
        'sales_count' => 'integer',
        'vencimiento_dias' => 'integer',
        'expiration_date' => 'date:Y-m-d',
        'expiry_alert_days' => 'integer',
        'expiry_alert_snoozed_until' => 'datetime',
        'expiry_alert_last_stage' => 'integer',
        'calc_config' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            if (! \App\Services\BatchStockService::hasLotTrackingSchema()) {
                unset(
                    $product->attributes['track_batches'],
                    $product->attributes['lot_deduction_policy'],
                    $product->attributes['allow_sell_expired']
                );
            }
            if (! \App\Services\BatchStockService::hasProductionRecipeSchema()) {
                unset(
                    $product->attributes['has_production_recipe'],
                    $product->attributes['recipe_yield_quantity']
                );
            }
        });
    }

    /**
     * Relación de Recetas / Combos
     * Un producto COMBO está compuesto por múltiples ingredientes (niños).
     */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_combos', 'parent_product_id', 'child_product_id')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    /**
     * Si este producto es un ingrediente, devuelve a qué Combos pertenece.
     */
    public function combos(): BelongsToMany
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
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Todos los lotes registrados para este producto.
     */
    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    /**
     * Lotes activos ordenados según política FEFO (próximos a vencer primero).
     */
    public function activeBatches(): HasMany
    {
        return $this->hasMany(ProductBatch::class)
            ->where('status', ProductBatch::STATUS_ACTIVE)
            ->orderByRaw('CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('expiration_date', 'asc')
            ->orderBy('id', 'asc');
    }

    /**
     * Ingredientes requeridos para elaborar este producto en cocina / pastelería.
     */
    public function recipeItems(): HasMany
    {
        return $this->hasMany(ProductRecipeItem::class, 'parent_product_id');
    }

    /**
     * Recetas de elaboración en las que este producto participa como materia prima o semi-elaborado.
     */
    public function usedInRecipes(): HasMany
    {
        return $this->hasMany(ProductRecipeItem::class, 'ingredient_product_id');
    }

    /**
     * Tramos de precio mayorista ordenados de menor a mayor.
     */
    public function priceTiers(): HasMany
    {
        return $this->hasMany(ProductPriceTier::class)->orderBy('min_quantity', 'asc');
    }

    /**
     * Búsqueda por palabras sueltas, sin importar el orden.
     *
     * Cada palabra debe aparecer (AND) en alguna de las columnas indicadas (OR).
     * Ej: "stanley verde" encuentra "STANLEY Termo 1L Verde" y "verde stanley" también.
     * Los comodines LIKE (% _) escritos por el usuario se tratan como texto literal.
     *
     * @param  array<int, string>  $columns  Columnas (pueden venir calificadas: products.name)
     */
    public function scopeMatchingSearch(Builder $query, ?string $search, array $columns = ['products.name', 'products.barcode', 'products.internal_code']): Builder
    {
        $terms = preg_split('/\s+/u', trim((string) $search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = array_slice($terms, 0, 6); // Tope de seguridad: máx 6 palabras

        foreach ($terms as $term) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $query->where(function (Builder $q) use ($columns, $like) {
                $grammar = $q->getQuery()->getGrammar();
                foreach ($columns as $column) {
                    $q->orWhereRaw($grammar->wrap($column)." LIKE ? ESCAPE '!'", [$like]);
                }
            });
        }

        return $query;
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
        $applicable = $tiers->filter(fn ($t) => $quantity >= (float) $t->min_quantity);

        return $applicable->isNotEmpty()
            ? (float) $applicable->last()->unit_price
            : (float) $this->selling_price;
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? asset('storage/'.$this->image_path) : null;
    }

    /**
     * External e-commerce channel product mappings.
     */
    public function ecommerceMappings(): HasMany
    {
        return $this->hasMany(EcommerceProductMapping::class);
    }

    /**
     * Ofertas y promociones temporales asociadas.
     */
    public function offers(): BelongsToMany
    {
        return $this->belongsToMany(Offer::class, 'offer_products')
            ->withPivot('special_price')
            ->withTimestamps();
    }

    /**
     * Calcula el costo total de un combo sumando (costo_hijo * cantidad_receta).
     */
    public function calculateComboCostPrice(): float
    {
        $children = $this->relationLoaded('children') ? $this->children : $this->children()->get();
        if ($children->isEmpty()) {
            return 0.0;
        }

        $totalCost = 0.0;
        foreach ($children as $child) {
            $qty = (float) ($child->pivot->quantity ?? 1);
            $totalCost += ((float) $child->cost_price) * $qty;
        }

        return round($totalCost, 2);
    }

    /**
     * Calcula cuántos combos enteros se pueden armar según el stock actual de sus ingredientes,
     * contemplando divisores fraccionarios periódicos (ej. 1/6 = 0.167, 1/12 = 0.083).
     */
    public function calculateComboAvailableStock(): float
    {
        $children = $this->relationLoaded('children') ? $this->children : $this->children()->get();
        if ($children->isEmpty()) {
            return 0.0;
        }

        $limiting = null;
        foreach ($children as $child) {
            $pivotQty = (float) ($child->pivot->quantity ?? 1);
            if ($pivotQty <= 0) {
                continue;
            }

            $childStock = max(0.0, (float) $child->stock);
            if ($childStock <= 0.001) {
                $availableForChild = 0.0;
            } else {
                $denom = (int) round(1.0 / $pivotQty);
                $isRepeatingUnitFraction = $pivotQty < 1.0
                    && $denom >= 2
                    && $denom <= 100
                    && abs($pivotQty - round(1.0 / $denom, 3)) < 0.0005
                    && abs($pivotQty - (1.0 / $denom)) > 0.00001;

                if ($isRepeatingUnitFraction) {
                    $wholeUnits = (int) floor($childStock + 0.001);
                    $remStock = max(0.0, $childStock - $wholeUnits);
                    $remPortions = $remStock > 0.006 ? floor(($remStock + 0.005) * $denom) : 0.0;
                    $availableForChild = ($wholeUnits * $denom) + $remPortions;
                } else {
                    $epsilon = $pivotQty < 1.0 ? min(0.003, $pivotQty * 0.02) : 0.0001;
                    $availableForChild = floor(($childStock + $epsilon) / $pivotQty);
                }
            }

            if ($limiting === null || $availableForChild < $limiting) {
                $limiting = $availableForChild;
            }
        }

        return (float) max(0, $limiting ?? 0);
    }

    public function toArray(): array
    {
        $array = parent::toArray();

        if ($this->is_combo && $this->exists) {
            if (! $this->relationLoaded('children')) {
                $this->loadMissing('children');
            }
            if ($this->children->isNotEmpty()) {
                $array['cost_price'] = number_format($this->calculateComboCostPrice(), 2, '.', '');
            }
            $array['stock'] = number_format($this->calculateComboAvailableStock(), 3, '.', '');
        }

        return $array;
    }
}
