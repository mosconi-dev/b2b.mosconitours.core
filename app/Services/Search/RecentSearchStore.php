<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;

/**
 * Per-user "recent searches" shortcuts, kept in the cache with a short TTL so the
 * list follows a user across devices without needing a table. The list itself
 * (dedup, ordering, cap, display strings) is shaped client-side; this seam only
 * stores and retrieves the already-shaped array.
 *
 * One subclass per product rather than one store taking a prefix: the two lists
 * hold different shapes, and a controller asking for its own type cannot be handed
 * the other product's history by accident.
 */
abstract class RecentSearchStore
{
    public function __construct(private readonly int $ttl) {}

    /** The cache key prefix, and with it which product's history this is. */
    abstract protected function prefix(): string;

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
        return $this->prefix().':'.$userId;
    }
}
