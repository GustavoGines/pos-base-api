<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThirdPartyCheck extends Model
{
    protected $fillable = [
        'bank_name',
        'check_number',
        'amount',
        'issue_date',
        'payment_date',
        'issuer_name',
        'issuer_cuit',
        'customer_id',
        'sale_id',
        'supplier_id',
        'cash_shift_id',
        'status',
        'endorsement_note',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'issue_date'   => 'date',
        'payment_date' => 'date',
    ];

    // ── Relaciones ────────────────────────────────────────────────────

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * Fase 2 – Endoso: proveedor al que se endosó el cheque.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
