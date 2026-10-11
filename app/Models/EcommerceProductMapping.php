<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EcommerceProductMapping extends Model
{
    use HasFactory;

    protected $table = 'ecommerce_product_mappings';

    protected $fillable = [
        'integration_id',
        'product_id',
        'platform',
        'external_product_id',
        'external_variant_id',
        'external_sku',
        'external_title',
        'sync_stock',
        'safety_stock_threshold',
        'allocated_stock_percentage',
        'is_paused',
        'last_known_local_stock',
        'last_known_external_stock',
        'last_synced_at',
        'sync_status',
        'sync_error_message',
    ];

    protected $casts = [
        'sync_stock' => 'boolean',
        'safety_stock_threshold' => 'decimal:2',
        'allocated_stock_percentage' => 'decimal:2',
        'is_paused' => 'boolean',
        'last_known_local_stock' => 'decimal:3',
        'last_known_external_stock' => 'decimal:3',
        'last_synced_at' => 'datetime',
    ];

    /**
     * Relationship to local product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Relationship to external integration channel.
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(EcommerceIntegration::class, 'integration_id');
    }

    /**
     * Relationship to sync logs.
     */
    public function syncLogs(): HasMany
    {
        return $this->hasMany(EcommerceSyncLog::class, 'mapping_id');
    }
}
