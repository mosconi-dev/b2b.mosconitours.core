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
        ]);
    }

    public function update(UpdateTboSettingsRequest $request, AuditLogger $audit): RedirectResponse
    {
        $environment = $request->validated('environment');
        $cacheKey = $request->validated('cache_key');
        $previous = $this->settings->get(TboEnvironmentResolver::SETTING_KEY);

        $this->settings->set(TboEnvironmentResolver::SETTING_KEY, $environment);
        $this->settings->set('tbo.cache_key', $cacheKey);

        $audit->log('tbo.settings_updated', null, [
            'environment' => ['from' => $previous, 'to' => $environment],
            'cache_key' => $cacheKey,
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
}
