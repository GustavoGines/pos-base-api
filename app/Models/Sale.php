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
        'user_id', 'cashier_id', 'customer_id', 'shipping_cost', 'delivery_address',
        'price_list',
        'voided_by_user_id', 'void_authorized_by_admin_id', 'voided_at', 'void_reason',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'total_surcharge' => 'decimal:2',
        'amount_due' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'voided_at' => 'datetime',
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

    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function voidAuthorizedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'void_authorized_by_admin_id');
    }

    public function deliveryNote()
    {
        return $this->hasOne(DeliveryNote::class);
    }

    /**
     * Cheques de terceros recibidos en esta venta.
     * IMPORTANTE: No agregar a ningún with([]) de listado global.
     */
    public function checks(): HasMany
    {
        return $this->hasMany(ThirdPartyCheck::class);
    }

    /**
     * Determina si la venta ya dedujo stock físico durante el checkout.
     */
    public function hasDeductedStock(): bool
    {
        return StockMovement::where('sale_id', $this->id)
            ->where('type', 'sale')
            ->where('notes', 'like', '%Ticket #'.$this->id.'%')
            ->exists();
    }
}
