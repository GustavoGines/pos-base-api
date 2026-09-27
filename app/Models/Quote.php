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
        $last = static::orderByDesc('id')->lockForUpdate()->value('quote_number');
        if (!$last) {
            return 'PRES-0001';
        }
        if (preg_match('/(\d+)$/', $last, $matches)) {
            $num = (int) $matches[1];
            $len = strlen($matches[1]);
            $prefix = substr($last, 0, -strlen($matches[1]));
            return $prefix . str_pad($num + 1, max(4, $len), '0', STR_PAD_LEFT);
        }
        return 'PRES-0001';
    }
}
