<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promo extends Model
{
    protected $fillable = ['store_id', 'code', 'name', 'type', 'value', 'min_belanja', 'start_date', 'end_date', 'quota', 'used', 'is_active'];

    protected $casts = [
        'value' => 'decimal:2', 'min_belanja' => 'decimal:2',
        'start_date' => 'date', 'end_date' => 'date', 'is_active' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
