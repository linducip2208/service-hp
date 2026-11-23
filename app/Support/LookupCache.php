<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Cache ringan (TTL 1 jam) untuk daftar referensi yang dipakai berulang
 * di dropdown form (produk, service, POS, laporan). Data master ini jarang
 * berubah; tanpa invalidasi eksplisit, TTL pendek menjaga kekonsistenan.
 */
class LookupCache
{
    public static function brands()
    {
        return Cache::remember('lookup:brands', 3600, fn () => Brand::orderBy('name')->get());
    }

    public static function categories()
    {
        return Cache::remember('lookup:categories', 3600, fn () => Category::orderBy('name')->get());
    }

    public static function activeStores()
    {
        return Cache::remember('lookup:stores:active', 3600, fn () => Store::where('is_active', true)->orderBy('name')->get());
    }

    public static function technicians()
    {
        return Cache::remember('lookup:technicians', 3600, fn () => User::where('role', 'teknisi')->where('is_active', true)->orderBy('name')->get());
    }

    public static function forgetAll(): void
    {
        foreach (['lookup:brands', 'lookup:categories', 'lookup:stores:active', 'lookup:technicians'] as $key) {
            Cache::forget($key);
        }
    }
}
