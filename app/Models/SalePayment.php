<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePayment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'sale_id', 'payment_method_id',
        'base_amount', 'surcharge_amount', 'total_amount',
    ];

    protected $casts = [
        'base_amount'     => 'decimal:2',
        'surcharge_amount' => 'decimal:2',
        'total_amount'    => 'decimal:2',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
