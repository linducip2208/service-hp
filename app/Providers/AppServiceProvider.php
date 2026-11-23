<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Share daftar toko ke layout (store switcher), toleran jika tabel belum ada
        view()->composer('layouts.tabler', function ($view) {
            try {
                if (\Schema::hasTable('stores')) {
                    $view->with('allStores', \App\Models\Store::where('is_active', true)->orderBy('name')->get());
                } else {
                    $view->with('allStores', collect());
                }
            } catch (\Throwable $e) {
                $view->with('allStores', collect());
            }
        });
    }
}
