<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Schema;

class Rubro extends Model
{
    use HasFactory;

    public const VALID_VERTICAL_TYPES = [
        'retail',
        'butcher',
        'hardware_store',
        'apparel',
        'tech',
        'general',
    ];

    protected $fillable = [
        'name',
        'description',
        'vertical_type',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public static function hasExtendedRubroColumns(): bool
    {
        return Schema::hasTable('rubros') && Schema::hasColumn('rubros', 'vertical_type');
    }

    protected static function booted(): void
    {
        static::saving(function (Rubro $rubro) {
            if (! Schema::hasTable('rubros')) {
                return;
            }
            if (! Schema::hasColumn('rubros', 'description')) {
                unset($rubro->attributes['description']);
            }
            if (! Schema::hasColumn('rubros', 'vertical_type')) {
                unset($rubro->attributes['vertical_type']);
            }
        });
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasManyThrough
    {
        return $this->hasManyThrough(Product::class, Category::class);
    }
}
