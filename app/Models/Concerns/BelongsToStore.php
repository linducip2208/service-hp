<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToStore
{
    public function scopeForStore(Builder $query, $storeId): Builder
    {
        if (blank($storeId)) {
            return $query;
        }

        return $query->where($this->getTable().'.store_id', $storeId);
    }

    public function scopeActiveStore(Builder $query): Builder
    {
        $storeId = auth()->user()->active_store_id ?? null;

        return $this->scopeForStore($query, $storeId);
    }
}
