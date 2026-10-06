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
        'difference_authorized_by_admin_id',
        'opened_at',
        'closed_at',
        'opening_balance',
        'expected_balance',
        'actual_balance',
        'difference',
        'cash_sales',
        'card_sales',
        'transfer_sales',
        'mp_sales',
        'mp_sales_count',
        'total_surcharge',
        'check_sales',
        'check_count',
        'check_details',
        'cc_sales',
        'cc_sales_count',
        'status',
        'total_expenses',
        'total_withdrawals',
        'total_deposits',
        'total_supplier_payments',
        'total_refunds',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        // Casteos estrictos a decimal para precisión financiera
        'opening_balance' => 'decimal:2',
        'expected_balance' => 'decimal:2',
        'actual_balance' => 'decimal:2',
        'difference' => 'decimal:2',
        'cash_sales' => 'decimal:2',
        'card_sales' => 'decimal:2',
        'transfer_sales' => 'decimal:2',
        'mp_sales' => 'decimal:2',
        'mp_sales_count' => 'integer',
        'total_surcharge' => 'decimal:2',
        'cc_sales' => 'decimal:2',
        'total_expenses' => 'decimal:2',
        'total_withdrawals' => 'decimal:2',
        'total_deposits' => 'decimal:2',
        'total_supplier_payments' => 'decimal:2',
        'total_refunds' => 'decimal:2',
    ];

    protected $appends = ['total_sales'];

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function differenceAuthorizedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'difference_authorized_by_admin_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function mpTransactions(): HasMany
    {
        return $this->hasMany(MpTransaction::class);
    }

    public function getTotalSalesAttribute()
    {
        return ($this->attributes['cash_sales'] ?? 0)
             + ($this->attributes['card_sales'] ?? 0)
             + ($this->attributes['transfer_sales'] ?? 0)
             + ($this->attributes['mp_sales'] ?? 0)
             + ($this->attributes['check_sales'] ?? 0)
             + ($this->attributes['cc_sales'] ?? 0);
    }
}
