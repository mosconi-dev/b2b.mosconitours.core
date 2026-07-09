<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Simple, cached key/value application settings backed by the `settings` table.
 * Reads are cached (settings change rarely); writes invalidate the cache key.
 */
class Settings
{
    public function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::rememberForever(
            $this->cacheKey($key),
            fn () => Setting::where('key', $key)->value('value'),
        );

        return $value ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget($this->cacheKey($key));
    }

    public function forget(string $key): void
    {
        Setting::where('key', $key)->delete();
        Cache::forget($this->cacheKey($key));
    }

    private function cacheKey(string $key): string
    {
        return "setting:{$key}";
    }
}
