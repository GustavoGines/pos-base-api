<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Detalle de cada insumo y lote específico consumido en una orden de producción.
 */
class ProductionOrderConsumption extends Model
{
    use HasFactory;

    protected $fillable = [
        'production_order_id',
        'ingredient_product_id',
        'batch_id',
        'quantity_consumed',
        'unit_cost',
        'subtotal_cost',
    ];

    protected $casts = [
        'quantity_consumed' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'subtotal_cost' => 'decimal:2',
    ];

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ingredient_product_id')->withTrashed();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }
}
