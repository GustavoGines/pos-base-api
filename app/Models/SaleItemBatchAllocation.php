<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registra qué cantidad de cada lote fue asignada a una venta y cuánto fue devuelto.
 */
class SaleItemBatchAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'sale_item_id',
        'product_id',
        'batch_id',
        'quantity',
        'restored_quantity',
        'unit_cost',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'restored_quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }

    /**
     * Cantidad neta pendiente de devolver en este cruce venta-lote.
     */
    public function getUnrestoredQuantityAttribute(): float
    {
        return max(0.0, round((float) $this->quantity - (float) $this->restored_quantity, 3));
    }
}
