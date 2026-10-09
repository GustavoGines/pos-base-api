<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectronicInvoice extends Model
{
    use HasFactory;

    protected $table = 'electronic_invoices';

    protected $fillable = [
        'sale_id',
        'voucher_type',
        'voucher_letter',
        'point_of_sale',
        'voucher_number',
        'cae',
        'cae_expiration',
        'doc_type',
        'doc_number',
        'receiver_name',
        'receiver_address',
        'receiver_tax_condition',
        'net_amount',
        'iva_amount',
        'tribute_amount',
        'exempt_amount',
        'untaxed_amount',
        'total_amount',
        'iva_breakdown',
        'tributes_breakdown',
        'qr_data',
        'status',
        'afip_request',
        'afip_response',
        'error_message',
        'issued_at',
        'credit_note_cae',
        'credit_note_expiration',
        'credit_note_number',
        'credit_note_issued_at',
        'credit_note_voucher_type',
        'credit_note_qr_data',
    ];

    protected $casts = [
        'voucher_type' => 'integer',
        'point_of_sale' => 'integer',
        'voucher_number' => 'integer',
        'doc_type' => 'integer',
        'cae_expiration' => 'date',
        'issued_at' => 'datetime',
        'credit_note_expiration' => 'date',
        'credit_note_issued_at' => 'datetime',
        'credit_note_voucher_type' => 'integer',
        'net_amount' => 'decimal:2',
        'iva_amount' => 'decimal:2',
        'tribute_amount' => 'decimal:2',
        'exempt_amount' => 'decimal:2',
        'untaxed_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'iva_breakdown' => 'array',
        'tributes_breakdown' => 'array',
        'afip_request' => 'array',
        'afip_response' => 'array',
    ];

    protected $appends = [
        'formatted_number',
    ];

    /**
     * Venta asociada a esta factura fiscal electrónica.
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * Retorna el número de comprobante formateado en estándar legal AFIP (ej. 00001-00000124).
     */
    public function getFormattedNumberAttribute(): string
    {
        return sprintf('%05d-%08d', (int) $this->point_of_sale, (int) $this->voucher_number);
    }

    /**
     * Determina si la factura está legalmente autorizada por AFIP/ARCA.
     */
    public function isAuthorized(): bool
    {
        return $this->status === 'authorized' && !empty($this->cae);
    }
}
