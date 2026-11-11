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
        'voucher_letter',
        'point_of_sale',
        'voucher_number',
        'net_21',
        'iva_21',
        'net_105',
        'iva_105',
        'net_27',
        'iva_27',
        'non_taxed_amount',
        'exempt_amount',
        'iva_perception',
        'iibb_perception',
        'internal_taxes',
        'affects_vat_book',
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
        'point_of_sale' => 'integer',
        'voucher_number' => 'integer',
        'net_21' => 'float',
        'iva_21' => 'float',
        'net_105' => 'float',
        'iva_105' => 'float',
        'net_27' => 'float',
        'iva_27' => 'float',
        'non_taxed_amount' => 'float',
        'exempt_amount' => 'float',
        'iva_perception' => 'float',
        'iibb_perception' => 'float',
        'internal_taxes' => 'float',
        'affects_vat_book' => 'boolean',
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

    public function getFormattedNumberAttribute(): string
    {
        if ($this->point_of_sale !== null && $this->voucher_number !== null) {
            $letter = $this->voucher_letter ? strtoupper($this->voucher_letter).' ' : '';

            return sprintf('%s%05d-%08d', $letter, (int) $this->point_of_sale, (int) $this->voucher_number);
        }

        return (string) ($this->invoice_number ?? 'S/N');
    }

    public function scopeForVatBook($query)
    {
        return $query->where('affects_vat_book', true);
    }
}
