<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MpWebhook extends Model
{
    use HasFactory;

    protected $fillable = [
        'action',
        'topic',
        'resource_id',
        'payload',
        'is_processed',
        'processed_at',
        'processing_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'is_processed' => 'boolean',
        'processed_at' => 'datetime',
    ];

    public function scopePending($query)
    {
        return $query->where('is_processed', false);
    }
}
