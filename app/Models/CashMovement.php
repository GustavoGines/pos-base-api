<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashMovement extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'cash_shift_id',
        'user_id',
        'authorized_by',
        'deleted_by',
        'supplier_id',
        'check_id',
        'amount',
        'payment_method',
        'type',
        'category',
        'description',
        'receipt_number',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function cashShift()
    {
        return $this->belongsTo(CashShift::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function authorizer()
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function check()
    {
        return $this->belongsTo(ThirdPartyCheck::class, 'check_id');
    }
}
