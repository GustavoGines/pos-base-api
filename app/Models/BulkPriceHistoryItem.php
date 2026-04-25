<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BulkPriceHistoryItem extends Model
{
    public $timestamps = false;
    
    protected $fillable = [
        'bulk_price_history_id', 'product_id', 
        'old_cost_price', 'new_cost_price',
        'old_selling_price', 'new_selling_price'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
