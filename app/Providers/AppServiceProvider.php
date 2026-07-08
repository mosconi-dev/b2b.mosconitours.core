<?php

namespace App\Providers;

use App\Services\Rbac\PermissionRegistry;
use App\Services\TboAir\FlightSearchCache;
use App\Services\TboAir\TboAirClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TboAirClient::class, fn () => new TboAirClient(config('tboair')));

        $this->app->singleton(FlightSearchCache::class, fn () => new FlightSearchCache((int) config('tboair.search_cache_ttl')));

        // One registry instance per request so its normalized module cache is shared.
        $this->app->singleton(PermissionRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
