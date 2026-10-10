<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class EcommerceIntegration extends Model
{
    use HasFactory;

    public const PLATFORM_MERCADOLIBRE = 'mercadolibre';

    public const PLATFORM_TIENDANUBE = 'tiendanube';

    public const PLATFORM_WOOCOMMERCE = 'woocommerce';

    public const PLATFORM_INTERNAL = 'ecommerce_platform';

    public const PLATFORMS = [
        self::PLATFORM_MERCADOLIBRE,
        self::PLATFORM_TIENDANUBE,
        self::PLATFORM_WOOCOMMERCE,
        self::PLATFORM_INTERNAL,
    ];

    protected $table = 'ecommerce_integrations';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'platform',
        'name',
        'store_id',
        'base_url',
        'client_id',
        'client_secret',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'webhook_secret',
        'is_active',
        'sync_stock_enabled',
        'sync_price_enabled',
        'rate_limit_per_minute',
        'batch_size',
        'timeout_seconds',
        'auto_pause_on_zero_stock',
        'settings',
        'last_health_check_at',
        'last_health_status',
        'consecutive_failures',
        'circuit_breaker_tripped_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'client_secret',
        'access_token',
        'refresh_token',
        'webhook_secret',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sync_stock_enabled' => 'boolean',
            'sync_price_enabled' => 'boolean',
            'auto_pause_on_zero_stock' => 'boolean',
            'rate_limit_per_minute' => 'integer',
            'batch_size' => 'integer',
            'timeout_seconds' => 'integer',
            'consecutive_failures' => 'integer',
            'settings' => 'array',
            'token_expires_at' => 'datetime',
            'last_health_check_at' => 'datetime',
            'circuit_breaker_tripped_at' => 'datetime',
        ];
    }

    /**
     * Decrypt an encrypted attribute value safely.
     */
    public function getDecryptedSecret(string $field): ?string
    {
        $value = $this->attributes[$field] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            Log::warning("No se pudo descifrar el campo {$field} para la integración {$this->id}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Encrypt a sensitive attribute before persisting.
     */
    public function setEncryptedSecret(string $field, ?string $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes[$field] = null;

            return;
        }

        // Preserve current stored secret if masked placeholder is passed
        if (str_contains($value, '****')) {
            return;
        }

        $this->attributes[$field] = Crypt::encryptString($value);
    }

    /**
     * Mutator for client_secret attribute.
     */
    public function setClientSecretAttribute(?string $value): void
    {
        $this->setEncryptedSecret('client_secret', $value);
    }

    /**
     * Mutator for access_token attribute.
     */
    public function setAccessTokenAttribute(?string $value): void
    {
        $this->setEncryptedSecret('access_token', $value);
    }

    /**
     * Mutator for refresh_token attribute.
     */
    public function setRefreshTokenAttribute(?string $value): void
    {
        $this->setEncryptedSecret('refresh_token', $value);
    }

    /**
     * Mutator for webhook_secret attribute.
     */
    public function setWebhookSecretAttribute(?string $value): void
    {
        $this->setEncryptedSecret('webhook_secret', $value);
    }

    /**
     * Accessor for decrypted client_secret.
     */
    public function getDecryptedClientSecretAttribute(): ?string
    {
        return $this->getDecryptedSecret('client_secret');
    }

    /**
     * Accessor for decrypted access_token.
     */
    public function getDecryptedAccessTokenAttribute(): ?string
    {
        return $this->getDecryptedSecret('access_token');
    }

    /**
     * Accessor for decrypted refresh_token.
     */
    public function getDecryptedRefreshTokenAttribute(): ?string
    {
        return $this->getDecryptedSecret('refresh_token');
    }

    /**
     * Accessor for decrypted webhook_secret.
     */
    public function getDecryptedWebhookSecretAttribute(): ?string
    {
        return $this->getDecryptedSecret('webhook_secret');
    }

    /**
     * Mask a sensitive string for safe presentation in UI/API responses.
     */
    public static function maskSecret(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $length = strlen($value);
        if ($length <= 8) {
            return '****';
        }

        return substr($value, 0, 4).'****'.substr($value, -4);
    }

    /**
     * Check if the circuit breaker is currently tripped.
     */
    public function isCircuitBreakerTripped(int $cooldownMinutes = 15): bool
    {
        if ($this->circuit_breaker_tripped_at === null) {
            return false;
        }

        return $this->circuit_breaker_tripped_at->copy()->addMinutes($cooldownMinutes)->isFuture();
    }

    /**
     * Record a successful communication with the platform.
     */
    public function recordSuccess(): void
    {
        $this->update([
            'last_health_status' => 'healthy',
            'last_health_check_at' => now(),
            'consecutive_failures' => 0,
            'circuit_breaker_tripped_at' => null,
        ]);
    }

    /**
     * Record a failure and trip the circuit breaker if the threshold is exceeded.
     */
    public function recordFailure(int $threshold = 5): void
    {
        $failures = (int) $this->consecutive_failures + 1;
        $trippedAt = $this->circuit_breaker_tripped_at;

        if ($failures >= $threshold && $trippedAt === null) {
            $trippedAt = now();
        }

        $this->update([
            'last_health_status' => 'error',
            'last_health_check_at' => now(),
            'consecutive_failures' => $failures,
            'circuit_breaker_tripped_at' => $trippedAt,
        ]);
    }

    /**
     * Relation to product mappings.
     */
    public function productMappings(): HasMany
    {
        return $this->hasMany('App\Models\EcommerceProductMapping', 'integration_id');
    }

    /**
     * Relation to synchronization logs.
     */
    public function syncLogs(): HasMany
    {
        return $this->hasMany('App\Models\EcommerceSyncLog', 'integration_id');
    }

    /**
     * Relation to incoming webhooks.
     */
    public function webhooks(): HasMany
    {
        return $this->hasMany('App\Models\EcommerceWebhook', 'integration_id');
    }
}
