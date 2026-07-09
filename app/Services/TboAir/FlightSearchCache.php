<?php

namespace App\Services\TboAir;

use App\Services\TboAir\DTO\SearchInput;
use Closure;
use Illuminate\Support\Facades\Cache;

class FlightSearchCache
{
    public function __construct(private readonly int $ttl) {}

    /**
     * Return the cached result for this user + environment + search, computing
     * (and caching) it via $resolver on a miss. The key is per-user AND
     * per-environment, so test and live results never bleed into each other.
     *
     * @param  Closure(): array<string, mixed>  $resolver
     * @return array<string, mixed>
     */
    public function remember(int $userId, string $environment, SearchInput $input, Closure $resolver): array
    {
        return Cache::remember($this->key($userId, $environment, $input), $this->ttl, $resolver);
    }

    public function key(int $userId, string $environment, SearchInput $input): string
    {
        return 'flight_search:'.$environment.':'.$userId.':'.substr(hash('sha256', json_encode($input->toArray())), 0, 32);
    }
}
