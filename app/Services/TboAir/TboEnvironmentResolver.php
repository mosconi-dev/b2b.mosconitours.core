<?php

namespace App\Services\TboAir;

use App\Services\Settings\Settings;

/**
 * Decides which TBO environment ("test"/"live") the current request uses.
 *
 * Precedence: per-user override → global setting → config default.
 * (The per-user branch is added in a later phase; today this resolves the
 * global setting then the config fallback.)
 */
class TboEnvironmentResolver
{
    public const SETTING_KEY = 'tbo.environment';

    public function __construct(private readonly Settings $settings) {}

    public function resolve(): string
    {
        $env = $this->settings->get(self::SETTING_KEY) ?: config('tboair.default', 'test');

        return $this->normalize((string) $env);
    }

    public function normalize(string $env): string
    {
        return in_array($env, ['test', 'live'], true) ? $env : 'test';
    }
}
