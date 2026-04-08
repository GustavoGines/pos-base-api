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
        'stock', 'min_stock', 'active', 'is_sold_by_weight', 'sales_count', 'vencimiento_dias',
        'unit_type', 'category_id', 'brand_id', 'supplier_id'
    ];

    protected $casts = [
        'active' => 'boolean',
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
}
