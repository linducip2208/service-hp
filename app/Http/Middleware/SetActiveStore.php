<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetActiveStore
{
    public function handle(Request $request, Closure $next)
    {
        if (auth()->check()) {
            $user = auth()->user();
            $storeId = session('active_store_id') ?: $user->active_store_id ?? $user->stores()->first()?->id;
            if ($storeId) {
                session(['active_store_id' => $storeId]);
                // share globally via view + app container
                app()->instance('activeStoreId', (int) $storeId);
                view()->share('activeStoreId', (int) $storeId);
            }
        }
        return $next($request);
    }
}
