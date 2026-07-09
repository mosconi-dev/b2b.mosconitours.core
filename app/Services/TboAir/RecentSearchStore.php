<?php

namespace App\Services\TboAir;

use Illuminate\Support\Facades\Cache;

/**
 * Per-user "recent searches" shortcuts, kept in the cache with a short TTL so the
 * list follows a user across devices without needing a table. The list itself
 * (dedup, ordering, cap, display strings) is shaped client-side; this seam only
 * stores and retrieves the already-shaped array.
 */
class RecentSearchStore
{
    public function __construct(private readonly int $ttl) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get(int $userId): array
    {
        $recent = Cache::get($this->key($userId), []);

        return is_array($recent) ? $recent : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $recent
     */
    public function put(int $userId, array $recent): void
    {
        Cache::put($this->key($userId), array_values($recent), $this->ttl);
    }

    public function key(int $userId): string
    {
        return 'flight_recent:'.$userId;
    }
}
