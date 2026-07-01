<?php

namespace App\Providers;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
