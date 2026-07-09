<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTboSettingsRequest;
use App\Services\Rbac\AuditLogger;
use App\Services\Settings\Settings;
use App\Services\TboAir\TboEnvironmentResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $base = $this->baseCacheKey();

        return view('admin.settings.index', [
            'effectiveEnvironment' => $this->resolver->resolve(),
            'globalEnvironment' => $this->settings->get(TboEnvironmentResolver::SETTING_KEY, config('tboair.default')),
            // Per environment: the cached TokenId and its TTL, shown as a pair — when a token is
            // cached we show its effective TTL alongside it; with no token both fields are blank.
            'environments' => [
                'test' => $this->environmentCard('test', $base),
                'live' => $this->environmentCard('live', $base),
            ],
        ]);
    }

    public function update(UpdateTboSettingsRequest $request, AuditLogger $audit): RedirectResponse
    {
        $environment = $request->validated('environment');
        $previousEnv = $this->settings->get(TboEnvironmentResolver::SETTING_KEY);

        $this->settings->set(TboEnvironmentResolver::SETTING_KEY, $environment);

        // The base cache key is no longer editable (it caused token/key confusion); the key
        // is always the config default now, so drop any value stored under the old field.
        $this->settings->forget('tbo.cache_key');

        $audit->log('tbo.settings_updated', null, [
            'environment' => ['from' => $previousEnv, 'to' => $environment],
        ]);

        return back()->with('status', "Global environment is now {$environment}. Valid cached tokens were kept.");
    }

    /**
     * Save one environment's token settings: its cache TTL and the cached TokenId. A non-empty
     * token is seeded into the cache slot so the next call REUSES it instead of authenticating
     * afresh (e.g. a token grabbed from the live system); it must still be valid and calls must
     * exit the TBO-whitelisted IP, else the ErrorCode-6 backstop re-authenticates. A BLANK token
     * clears the cached slot, so the next call re-authenticates for this environment.
     */
    public function updateEnvironment(Request $request, string $env, AuditLogger $audit): RedirectResponse
    {
        // TTL and TokenId are a pair: fill both to set a session, or leave both blank to reset
        // (clear the token + fall back to the default TTL). Exactly one without the other is rejected.
        $validated = $request->validateWithBag('tbo_'.$env, [
            'ttl' => ['nullable', 'required_with:token', 'integer', 'max:86400'],
            'token' => ['nullable', 'required_with:ttl', 'string', 'max:255'],
        ], [
            'ttl.required_with' => 'Enter a TTL, or clear the TokenId too.',
            'token.required_with' => 'Paste a TokenId, or clear the TTL too.',
        ]);

        $ttlRaw = $validated['ttl'] ?? null;

        if ($ttlRaw === null || $ttlRaw === '') {
            $this->settings->forget('tbo.token_ttl.'.$env); // empty = use the config default
        } else {
            $this->settings->set('tbo.token_ttl.'.$env, (int) $ttlRaw);
        }

        // Effective TTL after the change (per-env override, or the config default when blank).
        $ttl = $this->tokenTtl($env);
        $token = trim((string) ($validated['token'] ?? ''));

        if ($token !== '') {
            Cache::put($this->baseCacheKey().':'.$env, $token, $ttl);
            // Never log the raw token — keep a short, non-reversible hint for the audit trail.
            $audit->log('tbo.token_seeded', null, ['environment' => $env, 'token_hint' => '…'.substr($token, -6)]);
            $note = ' Token stored for reuse.';
        } else {
            // Blank token = clear the cached session so the next call re-authenticates for this env.
            $cleared = Cache::pull($this->baseCacheKey().':'.$env) !== null;
            if ($cleared) {
                $audit->log('tbo.token_flushed', null, ['environment' => $env]);
            }
            $note = $cleared ? ' Cached token cleared — the next call re-authenticates.' : '';
        }

        $audit->log('tbo.settings_updated', null, ['environment' => $env, 'token_ttl' => $ttl]);

        return back()->with('status', "Saved {$env} settings.".$note);
    }

    public function flushToken(string $env, AuditLogger $audit): RedirectResponse
    {
        Cache::forget($this->baseCacheKey().':'.$env);
        $audit->log('tbo.token_flushed', null, ['environment' => $env]);

        return back()->with('status', "Flushed the {$env} token — the next call re-authenticates.");
    }

    /**
     * View data for one environment card. TTL and TokenId render as a pair: when a token is
     * cached we show its effective TTL alongside it; with no token both fields stay blank.
     *
     * @return array{token: ?string, ttl: ?int}
     */
    private function environmentCard(string $env, string $base): array
    {
        $token = Cache::get($base.':'.$env);

        return [
            'token' => $token,
            'ttl' => $token !== null ? $this->tokenTtl($env) : null,
        ];
    }

    private function baseCacheKey(): string
    {
        return config('tboair.cache_key');
    }

    private function tokenTtl(string $env): int
    {
        return (int) ($this->settings->get("tbo.token_ttl.{$env}") ?: config('tboair.token_ttl'));
    }
}
