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
        'stock', 'min_stock', 'active', 'is_combo', 'is_sold_by_weight', 'sales_count', 'vencimiento_dias',
        'unit_type', 'category_id', 'brand_id', 'supplier_id', 'image_path',
    ];

    protected $appends = ['image_url'];

    protected $casts = [
        'active' => 'boolean',
        'is_combo' => 'boolean',
        'is_sold_by_weight' => 'boolean',
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'iva_rate' => 'decimal:2',
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
}
