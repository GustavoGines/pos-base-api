<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    protected $fillable = [
        'name', 'code', 'surcharge_type', 'surcharge_value',
        'is_cash', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'surcharge_value' => 'decimal:4',
        'is_cash'         => 'boolean',
        'is_active'       => 'boolean',
    ];

    public function salePayments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /**
     * Calcula el recargo en $ para un monto base dado.
     */
    public function calculateSurcharge(float $baseAmount): float
    {
        return match ($this->surcharge_type) {
            'percent' => round($baseAmount * (float)$this->surcharge_value / 100, 2),
            'fixed'   => (float)$this->surcharge_value,
            default   => 0.0,
        };
    }

    /**
     * Scope para traer solo los métodos activos, ordenados.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
