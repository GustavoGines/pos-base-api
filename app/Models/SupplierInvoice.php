<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierInvoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'supplier_id',
        'type',
        'status',
        'amount',
        'tax_amount',
        'freight_amount',
        'discount_amount',
        'invoice_number',
        'description',
        'receipt_file_url',
        'issue_date',
        'due_date',
        'user_id',
        'edit_count',
        'last_edited_at',
        'last_edited_by_id',
        'deleted_by_id',
        'deletion_reason',
    ];

    protected $casts = [
        'issue_date' => 'datetime',
        'due_date' => 'date',
        'last_edited_at' => 'datetime',
        'amount' => 'float',
        'tax_amount' => 'float',
        'freight_amount' => 'float',
        'discount_amount' => 'float',
        'edit_count' => 'integer',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }

    public function lastEditor()
    {
        return $this->belongsTo(User::class, 'last_edited_by_id')->withoutGlobalScopes();
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by_id')->withoutGlobalScopes();
    }

    public function items()
    {
        return $this->hasMany(SupplierInvoiceItem::class);
    }

    public function audits()
    {
        return $this->hasMany(SupplierInvoiceAudit::class, 'supplier_invoice_id')->orderBy('id', 'desc');
    }
}
