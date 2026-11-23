<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductStock extends Model
{
    use BelongsToStore;

    protected $fillable = ['product_id', 'store_id', 'qty', 'min_stock', 'location'];

    protected $casts = ['min_stock' => 'integer'];

    /**
     * Min. stok EFEKTIF per baris toko: product_stocks.min_stock
     * bila terisi, kalau NULL ikut global products.min_stock
     * (fallback 5 bila keduanya null). Tanpa query tambahan bila
     * relasi product sudah di-load.
     */
    public function effectiveMinStock(): int
    {
        if ($this->min_stock !== null) return (int) $this->min_stock;
        $g = $this->relationLoaded('product') ? ($this->product?->min_stock ?? null) : null;
        if ($g !== null) return (int) $g;
        // Relasi belum di-load: product global di-load malas sekali saja.
        if (!$this->relationLoaded('product')) {
            $g = $this->product?->min_stock ?? null;
            if ($g !== null) return (int) $g;
        }
        return 5;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
