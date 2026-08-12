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
     */
    public function remember(int $userId, string $environment, SearchInput $input, Closure $callback): mixed
    {
        $key = $this->key($userId, $environment, $input);

        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $result = $callback();

        if (! $result->isPartial()) {
            Cache::put($key, $result, $this->ttl);
        }

        return $result;
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
