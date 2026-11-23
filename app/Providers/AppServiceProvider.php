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
        // Share daftar toko + navigasi sidebar ke layout, toleran jika tabel belum ada
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
            try {
                $role = auth()->user()->role ?? null;
                $storeId = session('active_store_id') ?? auth()->user()->active_store_id ?? null;
                $view->with('navGroups', \App\Support\NavMenu::forRole($role));
                $view->with('navBadges', \App\Support\NavMenu::badges($storeId ? (int) $storeId : null));
                $view->with('navRoute', request()->route()?->getName());
            } catch (\Throwable $e) {
                $view->with('navGroups', []);
                $view->with('navBadges', []);
                $view->with('navRoute', null);
            }
        });
    }
}
