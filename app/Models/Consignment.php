<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consignment extends Model
{
    use BelongsToStore;

    protected $fillable = ['code', 'store_id', 'supplier_id', 'customer_name', 'product_id', 'product_item_id', 'titip_price', 'jual_price', 'commission', 'status'];

    protected $casts = [
        'titip_price' => 'decimal:2', 'jual_price' => 'decimal:2', 'commission' => 'decimal:2',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productItem(): BelongsTo
    {
        return $this->belongsTo(ProductItem::class);
    }
}
