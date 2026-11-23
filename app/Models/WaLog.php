<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaLog extends Model
{
    protected $fillable = ['store_id', 'phone', 'message', 'type', 'status', 'response'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
