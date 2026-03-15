<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = ['total', 'payment_method', 'status', 'cash_register_shift_id'];

    protected $casts = [
        'total'  => 'decimal:2',
    ];

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }

    public function cashRegisterShift(): BelongsTo
    {
        return $this->belongsTo(CashRegisterShift::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }
}
