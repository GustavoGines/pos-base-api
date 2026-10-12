<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Offer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'type',
        'discount_percentage',
        'discount_amount',
        'period_type',
        'days_of_week',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'discount_percentage' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'days_of_week' => 'array',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'offer_products')
            ->withPivot('special_price')
            ->withTimestamps();
    }

    /**
     * Scope para filtrar ofertas actualmente vigentes por fecha y estado activo.
     */
    public function scopeActive($query)
    {
        $now = Carbon::now();

        return $query->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $now);
            });
    }

    /**
     * Verifica si la oferta aplica para el día de hoy según días de la semana configurados.
     */
    public function isCurrentDayValid(): bool
    {
        if (empty($this->days_of_week)) {
            return true;
        }

        $currentDayName = strtolower(Carbon::now()->format('l')); // ej: monday, tuesday

        return in_array($currentDayName, array_map('strtolower', $this->days_of_week));
    }
}
