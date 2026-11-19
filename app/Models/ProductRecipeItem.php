<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Componente de receta de elaboración propia (cocina/pastelería), separado de `product_combos`.
 */
class ProductRecipeItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_product_id',
        'ingredient_product_id',
        'quantity',
        'waste_pct',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'waste_pct' => 'decimal:2',
    ];

    public function parentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ingredient_product_id');
    }
}
