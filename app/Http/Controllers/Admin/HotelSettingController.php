<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Supplier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateHotelSettingsRequest;
use App\Services\Rbac\AuditLogger;
use App\Services\Settings\Settings;
use App\Services\Supplier\SupplierEnvironmentResolver;
use App\Services\TboHotel\HotelSearchCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * TBO Hotel's own settings, separate from the TBO Air page.
 *
 * They share a vendor and nothing else. Air's page is mostly token management — a
 * cached TokenId per environment, its TTL, a flush — and none of that exists here:
 * hotels authenticate with Basic Auth on every call. What is left is the environment
 * itself and the one piece of state a switch leaves behind, the search cache.
 */
class HotelSettingController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
        private readonly SupplierEnvironmentResolver $resolver,
        private readonly HotelSearchCache $cache,
    ) {}

    public function index(): View
    {
        return view('admin.tbo-hotel.settings', [
            'effectiveEnvironment' => $this->resolver->resolve(Supplier::TboHotel),
            'globalEnvironment' => $this->resolver->globalFor(Supplier::TboHotel),
            'configDefault' => config('tbohotel.default'),
            // Whether an override is even in play, so "effective" never looks arbitrary.
            'userOverride' => auth()->user()?->tbo_environment,
            'airEnvironment' => $this->resolver->resolve(Supplier::TboAir),
            'environments' => [
                'test' => $this->environmentCard('test'),
                'live' => $this->environmentCard('live'),
            ],
            'cacheTtl' => (int) config('tbohotel.search_cache_ttl'),
            'cacheGeneration' => $this->cache->generation(),
        ]);
    }

    public function update(UpdateHotelSettingsRequest $request, AuditLogger $audit): RedirectResponse
    {
        $environment = $request->validated('environment');
        $previous = $this->resolver->globalFor(Supplier::TboHotel);

        $this->settings->set(Supplier::TboHotel->settingKey(), $environment);

        $audit->log('tbohotel.settings_updated', null, [
            'environment' => ['from' => $previous, 'to' => $environment],
        ]);

        // Cached searches are keyed by environment already, so nothing stale can be
        // served across the switch and there is nothing to clear.
        return back()->with('status', "TBO Hotel global environment is now {$environment}.");
    }

    public function flushCache(AuditLogger $audit): RedirectResponse
    {
        $generation = $this->cache->flush();

        $audit->log('tbohotel.search_cache_flushed', null, ['generation' => $generation]);

        return back()->with('status', 'Cleared every cached hotel search. The next search asks TBO afresh.');
    }

    /**
     * What one environment is actually configured to talk to.
     *
     * The password is reported as present or missing and never shown: the point is to
     * answer "will live work if I switch to it", which does not require reading it.
     *
     * @return array{base_url: string, username: ?string, configured: bool}
     */
    private function environmentCard(string $env): array
    {
        $credentials = config("tbohotel.environments.{$env}.credentials", []);
        $username = $credentials['username'] ?? null;

        return [
            'base_url' => (string) config("tbohotel.environments.{$env}.base_url"),
            'username' => $username,
            'configured' => filled($username) && filled($credentials['password'] ?? null),
        ];
    }
}
