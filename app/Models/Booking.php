<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    use BelongsToStore;

    protected $fillable = ['code', 'store_id', 'customer_name', 'customer_phone', 'device', 'complaint', 'schedule_date', 'status', 'ticket_id'];

    protected $casts = ['schedule_date' => 'datetime'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
