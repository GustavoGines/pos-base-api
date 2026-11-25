<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'document_number',
        'document_type',
        'tax_condition',
        'fiscal_address',
        'credit_limit',
        'balance',
        'is_active',
        'default_price_tier',
        'delivery_address',
        'is_internal_account',
        'applies_iibb_perception',
        'iibb_perception_rate',
    ];

    protected $casts = [
        'document_type' => 'integer',
        'credit_limit' => 'decimal:2',
        'balance' => 'decimal:2',
        'is_active' => 'boolean',
        'is_internal_account' => 'boolean',
        'applies_iibb_perception' => 'boolean',
        'iibb_perception_rate' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            if ($customer->document_number !== null) {
                $trimmed = trim((string) $customer->document_number);
                $customer->document_number = $trimmed === '' ? null : $trimmed;
            }

            if ($customer->tax_condition === null || trim((string) $customer->tax_condition) === '') {
                $customer->tax_condition = 'consumidor_final';
            }

            $requiresCuit = ($customer->tax_condition !== 'consumidor_final')
                || (bool) $customer->applies_iibb_perception;

            if ($customer->document_number === null) {
                if (! $requiresCuit) {
                    $customer->document_type = 99;
                }
            } elseif ($customer->document_type === null || (int) $customer->document_type === 99) {
                $digits = preg_replace('/\D/', '', (string) $customer->document_number);
                $customer->document_type = ($requiresCuit || strlen($digits) === 11) ? 80 : 96;
            }
        });
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CustomerTransaction::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * Cheques de terceros aportados por este cliente.
     * IMPORTANTE: No agregar a ningún with([]) de listado global.
     */
    public function checks(): HasMany
    {
        return $this->hasMany(ThirdPartyCheck::class);
    }
}
