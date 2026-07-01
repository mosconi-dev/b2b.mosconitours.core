<?php

namespace App\Services\TboAir;

use App\Services\TboAir\DTO\SearchInput;
use Closure;
use Illuminate\Support\Facades\Cache;

class FlightSearchCache
{
    public function __construct(private readonly int $ttl) {}

    /**
     * Return the cached result for this user + search, computing (and caching)
     * it via $resolver on a miss. The key is per-user, so users never share
     * cached results; identical searches by the same user are deduped.
     *
     * @param  Closure(): array<string, mixed>  $resolver
     * @return array<string, mixed>
     */
    public function remember(int $userId, SearchInput $input, Closure $resolver): array
    {
        return Cache::remember($this->key($userId, $input), $this->ttl, $resolver);
    }

    public function key(int $userId, SearchInput $input): string
    {
        return 'flight_search:'.$userId.':'.substr(hash('sha256', json_encode($input->toArray())), 0, 32);
    }
}
