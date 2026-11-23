<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Debt extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'type', 'party_name', 'ref_type', 'ref_id', 'total', 'paid', 'remaining', 'due_date', 'status'];

    protected $casts = [
        'total' => 'decimal:2', 'paid' => 'decimal:2', 'remaining' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
