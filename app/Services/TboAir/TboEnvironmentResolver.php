<?php

namespace App\Services\TboAir;

use App\Models\User;
use App\Services\Settings\Settings;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * Decides which TBO environment ("test"/"live") applies.
 *
 * Precedence: per-user override → global setting → config default.
 * A per-user override to "live" is only honored when the user holds the
 * supplier.tbo.live permission; otherwise it falls back to "test". The global
 * setting is a deliberate platform-wide choice and is not per-user gated.
 */
class TboEnvironmentResolver
{
    public const SETTING_KEY = 'tbo.environment';

    public function __construct(private readonly Settings $settings) {}

    public function resolve(?Authenticatable $user = null): string
    {
        $user ??= Auth::user();

        if ($user instanceof User && $user->tbo_environment) {
            $env = $this->normalize($user->tbo_environment);

            if ($env === 'live' && ! $user->can('supplier.tbo.live')) {
                return 'test'; // override to live not permitted — safe fallback
            }

            return $env;
        }

        return $this->normalize($this->settings->get(self::SETTING_KEY) ?: config('tboair.default', 'test'));
    }

    public function normalize(string $env): string
    {
        return in_array($env, ['test', 'live'], true) ? $env : 'test';
    }
}
