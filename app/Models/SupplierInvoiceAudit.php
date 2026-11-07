<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierInvoiceAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_invoice_id',
        'user_id',
        'authorized_by_id',
        'action',
        'reason',
        'before_snapshot',
        'after_snapshot',
        'changes_summary',
    ];

    protected $casts = [
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
        'changes_summary' => 'array',
    ];

    public function invoice()
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id')->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')->withoutGlobalScopes();
    }

    public function authorizer()
    {
        return $this->belongsTo(User::class, 'authorized_by_id')->withoutGlobalScopes();
    }
}
