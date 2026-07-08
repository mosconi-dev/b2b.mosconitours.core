<?php

namespace App\Providers;

use App\Services\Rbac\PermissionRegistry;
use App\Services\Settings\Settings;
use App\Services\TboAir\FlightSearchCache;
use App\Services\TboAir\TboAirClient;
use App\Services\TboAir\TboAirConfig;
use App\Services\TboAir\TboEnvironmentResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Settings::class);

        // Resolved per request so the client always reflects the current
        // environment (global setting / per-user override), not a boot-time value.
        $this->app->bind(TboAirClient::class, function ($app) {
            $env = $app->make(TboEnvironmentResolver::class)->resolve();

            return new TboAirClient(TboAirConfig::for($env));
        });

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
