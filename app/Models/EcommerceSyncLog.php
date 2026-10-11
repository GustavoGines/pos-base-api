<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EcommerceSyncLog extends Model
{
    use HasFactory;

    protected $table = 'ecommerce_sync_logs';

    protected $fillable = [
        'integration_id',
        'mapping_id',
        'external_order_id',
        'direction',
        'action',
        'stock_before',
        'stock_after',
        'request_payload',
        'response_payload',
        'http_status',
        'duration_ms',
        'status',
        'error_message',
    ];

    protected $casts = [
        'stock_before' => 'decimal:3',
        'stock_after' => 'decimal:3',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'http_status' => 'integer',
        'duration_ms' => 'integer',
    ];

    /**
     * Relationship to external integration channel.
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(EcommerceIntegration::class, 'integration_id');
    }

    /**
     * Relationship to product mapping if applicable.
     */
    public function mapping(): BelongsTo
    {
        return $this->belongsTo(EcommerceProductMapping::class, 'mapping_id');
    }
}
