<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceSparepart extends Model
{
    protected $fillable = ['service_ticket_id', 'product_id', 'qty', 'price', 'subtotal'];

    protected $casts = ['price' => 'decimal:2', 'subtotal' => 'decimal:2'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class, 'service_ticket_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
