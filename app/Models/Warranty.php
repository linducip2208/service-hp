<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Warranty extends Model
{
    protected $fillable = ['ref_type', 'ref_id', 'customer_id', 'product_item_id', 'start_date', 'end_date', 'type', 'claim_note', 'status'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function productItem(): BelongsTo
    {
        return $this->belongsTo(ProductItem::class);
    }
}
