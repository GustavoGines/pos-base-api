<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashShift extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_register_id',
        'user_id',
        'closed_by_user_id',
        'opened_at',
        'closed_at',
        'opening_balance',
        'expected_balance',
        'actual_balance',
        'difference',
        'cash_sales',
        'card_sales',
        'transfer_sales',
        'total_surcharge',
        'status',
    ];

    protected $casts = [
        'opened_at'        => 'datetime',
        'closed_at'        => 'datetime',
        // Casteos estrictos a decimal para precisión financiera
        'opening_balance'  => 'decimal:2',
        'expected_balance' => 'decimal:2',
        'actual_balance'   => 'decimal:2',
        'difference'       => 'decimal:2',
        'cash_sales'       => 'decimal:2',
        'card_sales'       => 'decimal:2',
        'transfer_sales'   => 'decimal:2',
        'total_surcharge'  => 'decimal:2',
    ];

    /**
     * @return BelongsTo
     */
    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo
     */
    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /**
     * @return HasMany
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
