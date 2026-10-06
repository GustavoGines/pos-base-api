<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MpTransaction extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_OPENED = 'opened';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'sale_id',
        'cash_shift_id',
        'user_id',
        'external_reference',
        'collector_id',
        'pos_id',
        'amount',
        'status',
        'mp_payment_id',
        'mp_order_id',
        'qr_data',
        'payer_email',
        'payment_method_type',
        'payload_received',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payload_received' => 'array',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function cashShift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
