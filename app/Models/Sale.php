<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'total', 'total_surcharge', 'payment_status', 'amount_due',
        'status', 'cash_shift_id', 'tendered_amount', 'change_amount',
        'user_id', 'cashier_id', 'customer_id',
    ];

    protected $casts = [
        'total'           => 'decimal:2',
        'total_surcharge' => 'decimal:2',
        'amount_due'      => 'decimal:2',
    ];

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }

    public function cashShift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deliveryNote()
    {
        return $this->hasOne(DeliveryNote::class);
    }
}
