<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Quote extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_number', 'status', 'subtotal', 'total',
        'notes', 'customer_name', 'customer_phone',
        'valid_until', 'user_id', 'price_list',
    ];

    protected $casts = [
        'subtotal'    => 'decimal:2',
        'total'       => 'decimal:2',
        'valid_until' => 'date',
    ];

    public function items()
    {
        return $this->hasMany(QuoteItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Genera el próximo número de presupuesto (PRES-XXXX).
     */
    public static function nextQuoteNumber(): string
    {
        $last = static::latest('id')->value('quote_number');
        if (!$last) {
            return 'PRES-0001';
        }
        $num = (int) substr($last, 5);
        return 'PRES-' . str_pad($num + 1, 4, '0', STR_PAD_LEFT);
    }
}
