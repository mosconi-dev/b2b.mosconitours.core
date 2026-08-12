<?php

namespace App\Providers;

use App\Enums\Supplier;
use App\Services\Rbac\AuditLogger;
use App\Services\Rbac\PermissionRegistry;
use App\Services\Settings\Settings;
use App\Services\Supplier\SupplierEnvironmentResolver;
use App\Services\TboAir\FlightSearchCache;
use App\Services\TboAir\RecentSearchStore;
use App\Services\TboAir\TboAirClient;
use App\Services\TboAir\TboAirConfig;
use App\Services\TboHotel\TboHotelClient;
use App\Services\TboHotel\TboHotelConfig;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
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
            $env = $app->make(SupplierEnvironmentResolver::class)->resolve(Supplier::TboAir);

            return new TboAirClient(TboAirConfig::for($env));
        });

        // Same per-request resolution for hotels. No token to cache here — Basic Auth
        // rides on every call — so the client is nothing but its environment.
        $this->app->bind(TboHotelClient::class, function ($app) {
            $env = $app->make(SupplierEnvironmentResolver::class)->resolve(Supplier::TboHotel);

            return new TboHotelClient(TboHotelConfig::for($env));
        });

        $this->app->singleton(FlightSearchCache::class, fn () => new FlightSearchCache((int) config('tboair.search_cache_ttl')));

        $this->app->singleton(RecentSearchStore::class, fn () => new RecentSearchStore((int) config('tboair.recent_ttl')));

        // One registry instance per request so its normalized module cache is shared.
        $this->app->singleton(PermissionRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Record sign-in / sign-out as audit events (shown in the per-user activity feed).
        Event::listen(Login::class, function (Login $event): void {
            app(AuditLogger::class)->log('auth.login', null, [], 'Signed in', $event->user);
        });

        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->user) {
                app(AuditLogger::class)->log('auth.logout', null, [], 'Signed out', $event->user);
            }
        });
    }
}
