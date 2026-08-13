<?php

namespace App\Services\TboHotel;

use App\Services\Settings\Settings;
use App\Services\TboHotel\DTO\SearchInput;
use App\Services\TboHotel\DTO\SearchResult;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Per-user, per-environment cache of a hotel search.
 *
 * The TTL is a safety property here, not a performance one. TBO's BookingCode dies
 * thirty minutes after the search that produced it, so a cached price that outlived
 * its code would offer an agent a room they cannot book. Ten minutes leaves the
 * whole wizard inside the window.
 *
 * A partial result is never cached: it would freeze a transient failure in place for
 * ten minutes and make retrying pointless.
 *
 * What is stored is the rendered payload, not the SearchResult itself. `cache.serializable_classes`
 * is false — the framework refuses to unserialize any object out of the cache, so a
 * stored object graph comes back as __PHP_Incomplete_Class and blows up on first use.
 * The array is also an order of magnitude smaller: every offer carries an Eloquent
 * Hotel, and a serialized city ran to several megabytes.
 */
class HotelSearchCache
{
    /** Bumping this orphans every cached search at once. See flush(). */
    private const GENERATION = 'tbohotel.search_cache_generation';

    public function __construct(private readonly int $ttl, private readonly Settings $settings) {}

    public function key(int $userId, string $environment, SearchInput $input): string
    {
        return "hotel_search:{$environment}:g{$this->generation()}:{$userId}:{$input->fingerprint()}";
    }

    /**
     * Drop every cached search, for everyone.
     *
     * By moving the generation rather than deleting rows. There is no portable way to
     * enumerate keys by prefix — the database store cannot, tags are unsupported on it,
     * and Cache::flush() would take the RBAC and settings caches with it. Orphaned
     * entries are unreachable immediately and expire on their own inside the TTL.
     */
    public function flush(): int
    {
        $next = $this->generation() + 1;

        $this->settings->set(self::GENERATION, $next);

        return $next;
    }

    public function generation(): int
    {
        return (int) $this->settings->get(self::GENERATION, 0);
    }

    /**
     * @param  Closure(): SearchResult  $callback
     * @return array<string, mixed>
     */
    public function remember(int $userId, string $environment, SearchInput $input, Closure $callback): array
    {
        $key = $this->key($userId, $environment, $input);
        $cached = Cache::get($key);

        // Anything that is not an array is something we can no longer read — an
        // object left by an older build, refused on the way out. Recompute rather
        // than hand the caller a husk.
        if (is_array($cached)) {
            return $cached;
        }

        $result = $callback();

        if (! $result->isPartial()) {
            Cache::put($key, $result->toArray(), $this->ttl);
        }

        return $result->toArray();
    }

    public function forget(int $userId, string $environment, SearchInput $input): void
    {
        Cache::forget($this->key($userId, $environment, $input));
    }

    public function ttl(): int
    {
        return $this->ttl;
    }
}
