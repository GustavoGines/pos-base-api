<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Orden de producción / horneado que transforma insumos en un lote terminado.
 */
class ProductionOrder extends Model
{
    use HasFactory;

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'order_number',
        'product_id',
        'produced_batch_id',
        'quantity_produced',
        'total_cost',
        'unit_cost',
        'manufactured_at',
        'expiration_date',
        'status',
        'notes',
        'user_id',
        'authorized_by_admin_id',
    ];

    protected $casts = [
        'quantity_produced' => 'decimal:3',
        'total_cost' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'manufactured_at' => 'date:Y-m-d',
        'expiration_date' => 'date:Y-m-d',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function producedBatch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'produced_batch_id');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(ProductionOrderConsumption::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function authorizedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_admin_id');
    }
}
