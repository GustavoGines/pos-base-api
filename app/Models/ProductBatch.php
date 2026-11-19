<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Representa un lote físico de producto con trazabilidad de origen, costo y vencimiento.
 */
class ProductBatch extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DEFICIT = 'deficit';

    public const STATUS_EXHAUSTED = 'exhausted';

    public const STATUS_WRITTEN_OFF = 'written_off';

    public const STATUS_VOIDED = 'voided';

    public const SOURCE_PURCHASE = 'purchase';

    public const SOURCE_PRODUCTION = 'production';

    public const SOURCE_OPENING = 'opening';

    public const SOURCE_ADJUSTMENT = 'adjustment';

    public const SOURCE_MIGRATION = 'migration';

    public const SOURCE_DEFICIT_AUTO = 'deficit_auto';

    protected $fillable = [
        'product_id',
        'batch_code',
        'source_type',
        'supplier_invoice_id',
        'supplier_id',
        'production_order_id',
        'manufactured_at',
        'expiration_date',
        'initial_quantity',
        'remaining_quantity',
        'reserved_quantity',
        'unit_cost',
        'status',
        'alert_snoozed_until',
        'alert_last_stage',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'manufactured_at' => 'date:Y-m-d',
        'expiration_date' => 'date:Y-m-d',
        'initial_quantity' => 'decimal:3',
        'remaining_quantity' => 'decimal:3',
        'reserved_quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'alert_snoozed_until' => 'datetime',
        'alert_last_stage' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class)->withTrashed();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SaleItemBatchAllocation::class, 'batch_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'batch_id');
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    /**
     * Filtra únicamente lotes activos disponibles para venta.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Cantidad física libre disponible para venta inmediata en mostrador.
     */
    public function getAvailableToSellAttribute(): float
    {
        return max(0.0, round((float) $this->remaining_quantity - (float) $this->reserved_quantity, 3));
    }

    /**
     * Indica si el lote ya venció respecto al inicio del día actual.
     */
    public function isExpired(): bool
    {
        if (! $this->expiration_date) {
            return false;
        }

        return $this->expiration_date->copy()->startOfDay()->lt(now()->startOfDay());
    }
}
