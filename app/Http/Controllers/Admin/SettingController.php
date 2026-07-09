<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTboSettingsRequest;
use App\Services\Rbac\AuditLogger;
use App\Services\Settings\Settings;
use App\Services\TboAir\TboEnvironmentResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
        private readonly TboEnvironmentResolver $resolver,
    ) {}

    public function index(): View
    {
        return view('admin.settings.index', [
            'effectiveEnvironment' => $this->resolver->resolve(),
            'globalEnvironment' => $this->settings->get(TboEnvironmentResolver::SETTING_KEY, config('tboair.default')),
            'cacheKey' => $this->baseCacheKey(),
            'ttlTest' => $this->tokenTtl('test'),
            'ttlLive' => $this->tokenTtl('live'),
        ]);
    }

    public function update(UpdateTboSettingsRequest $request, AuditLogger $audit): RedirectResponse
    {
        $environment = $request->validated('environment');
        $cacheKey = $request->validated('cache_key');
        $ttl = ['test' => (int) $request->validated('ttl_test'), 'live' => (int) $request->validated('ttl_live')];

        $previousEnv = $this->settings->get(TboEnvironmentResolver::SETTING_KEY);
        $previousBase = $this->baseCacheKey();
        $previousTtl = ['test' => $this->tokenTtl('test'), 'live' => $this->tokenTtl('live')];

        $this->settings->set(TboEnvironmentResolver::SETTING_KEY, $environment);
        $this->settings->set('tbo.cache_key', $cacheKey);
        $this->settings->set('tbo.token_ttl.test', $ttl['test']);
        $this->settings->set('tbo.token_ttl.live', $ttl['live']);

        // Side effect handling: changing an env's TTL (or the base key) doesn't shrink an
        // already-cached token, so flush the affected token(s) — old and new key — to
        // re-mint with the new lifetime on the next call.
        $baseChanged = $cacheKey !== $previousBase;
        foreach (['test', 'live'] as $env) {
            if ($baseChanged || $ttl[$env] !== $previousTtl[$env]) {
                Cache::forget($cacheKey.':'.$env);
                Cache::forget($previousBase.':'.$env);
            }
        }

        $audit->log('tbo.settings_updated', null, [
            'environment' => ['from' => $previousEnv, 'to' => $environment],
            'cache_key' => $cacheKey,
            'token_ttl' => $ttl,
        ]);

        return back()->with('status', "TBO settings saved — global environment is now {$environment}.");
    }

    public function flushToken(string $env, AuditLogger $audit): RedirectResponse
    {
        Cache::forget($this->baseCacheKey().':'.$env);
        $audit->log('tbo.token_flushed', null, ['environment' => $env]);

        return back()->with('status', "Flushed the {$env} token — the next call re-authenticates.");
    }

    private function baseCacheKey(): string
    {
        return $this->settings->get('tbo.cache_key', config('tboair.cache_key'));
    }

    private function tokenTtl(string $env): int
    {
        return (int) ($this->settings->get("tbo.token_ttl.{$env}") ?: config('tboair.token_ttl'));
    }
}
