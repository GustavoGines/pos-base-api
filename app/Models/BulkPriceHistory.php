<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BulkPriceHistory extends Model
{
    protected $fillable = [
        'user_id', 'percentage', 'rounding_rule', 'target_field',
        'filters', 'affected_count', 'reverted', 'reverted_at'
    ];

    protected $casts = [
        'filters' => 'array',
        'reverted' => 'boolean',
        'reverted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(BulkPriceHistoryItem::class);
    }
}
