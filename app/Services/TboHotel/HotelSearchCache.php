<?php

namespace App\Services\TboHotel;

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
    public function __construct(private readonly int $ttl) {}

    public function key(int $userId, string $environment, SearchInput $input): string
    {
        return "hotel_search:{$environment}:{$userId}:{$input->fingerprint()}";
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
