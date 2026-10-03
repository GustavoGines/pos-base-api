<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'rubro_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (Category $category) {
            if (empty($category->rubro_id)) {
                $defaultId = Rubro::where('is_system', true)->value('id') ?? Rubro::value('id');
                if ($defaultId) {
                    $category->rubro_id = $defaultId;
                }
            }
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function rubro(): BelongsTo
    {
        return $this->belongsTo(Rubro::class);
    }
}
