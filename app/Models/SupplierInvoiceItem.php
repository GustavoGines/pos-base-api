<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierInvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_invoice_id',
        'product_id',
        'quantity',
        'previous_cost',
        'unit_cost',
        'new_selling_price',
        'subtotal',
    ];

    protected $casts = [
        'quantity' => 'float',
        'previous_cost' => 'float',
        'unit_cost' => 'float',
        'new_selling_price' => 'float',
        'subtotal' => 'float',
    ];

    public function invoice()
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
