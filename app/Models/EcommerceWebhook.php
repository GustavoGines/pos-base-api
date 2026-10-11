<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EcommerceWebhook extends Model
{
    use HasFactory;

    protected $table = 'ecommerce_webhooks';

    protected $fillable = [
        'integration_id',
        'platform',
        'event_type',
        'external_event_id',
        'event_timestamp',
        'payload',
        'headers',
        'status',
        'processed_at',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'event_timestamp' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /**
     * Relationship to external integration channel.
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(EcommerceIntegration::class, 'integration_id');
    }
}
