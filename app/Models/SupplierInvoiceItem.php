<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierInvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_invoice_id',
        'product_id',
        'batch_id',
        'batch_code',
        'expiration_date',
        'quantity',
        'previous_cost',
        'unit_cost',
        'new_selling_price',
        'subtotal',
    ];

    protected $casts = [
        'quantity' => 'float',
        'previous_cost' => 'float',
        'unit_cost' => 'float',
        'new_selling_price' => 'float',
        'subtotal' => 'float',
        'expiration_date' => 'date:Y-m-d',
    ];

    protected static function booted(): void
    {
        static::saving(function (SupplierInvoiceItem $item) {
            if (! \App\Services\BatchStockService::hasLotTrackingSchema()) {
                unset(
                    $item->attributes['batch_id'],
                    $item->attributes['batch_code'],
                    $item->attributes['expiration_date']
                );
            }
        });
    }

    public function invoice()
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function batch()
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }
}
