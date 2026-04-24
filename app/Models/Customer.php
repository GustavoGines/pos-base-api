<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'document_number',
        'credit_limit',
        'balance',
        'is_active',
        'default_price_tier',
        'delivery_address',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * @return HasMany
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(CustomerTransaction::class);
    }

    /**
     * @return HasMany
     */
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
