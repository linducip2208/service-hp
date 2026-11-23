<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecondhandPurchase extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'customer_id', 'product_id', 'imei', 'condition_grade', 'buy_price', 'sell_price_plan', 'status', 'user_id'];

    protected $casts = ['buy_price' => 'decimal:2', 'sell_price_plan' => 'decimal:2'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
