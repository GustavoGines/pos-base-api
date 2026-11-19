<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use HasFactory;

    /**
     * Token canónico en `notes` que identifica que una venta dedujo stock en el mostrador (checkout),
     * diferenciándolo de los movimientos diferidos de logística ("Despacho Logístico Remito #...").
     */
    public const CHECKOUT_NOTE_TOKEN = 'Ticket #';

    protected $fillable = [
        'product_id', 'user_id', 'authorized_by_admin_id', 'type', 'quantity',
        'unit_cost', 'notes', 'supplier_invoice_id', 'batch_id', 'cash_shift_id', 'sale_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (StockMovement $movement) {
            if (! \App\Services\BatchStockService::hasLotTrackingSchema()) {
                unset(
                    $movement->attributes['batch_id'],
                    $movement->attributes['unit_cost']
                );
                if (($movement->attributes['type'] ?? null) === 'waste') {
                    $movement->attributes['type'] = 'out';
                }
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function authorizedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_admin_id');
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }
}
