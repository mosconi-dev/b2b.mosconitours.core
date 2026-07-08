<?php

namespace App\Services\Rbac;

use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Single seam for RBAC permission caching.
 *
 * All cache-key construction lives here, so the invalidation strategy can later
 * evolve (e.g. to a global version tag: "rbac.v{n}.user.{id}") without touching
 * any caller — only bumpVersion()/keyFor() would change.
 */
class RbacCache
{
    public function ttl(): int
    {
        return (int) config('rbac.cache_ttl', 3600);
    }

    public function keyFor(int|User $user): string
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return "rbac.user.{$id}.permissions";
    }

    /**
     * @param  Closure():array<int, string>  $resolver
     * @return array<int, string>
     */
    public function remember(User $user, Closure $resolver): array
    {
        return Cache::remember($this->keyFor($user), $this->ttl(), $resolver);
    }

    public function flushUser(int|User $user): void
    {
        Cache::forget($this->keyFor($user));
    }

    /**
     * Forget the cached permissions of every member of a role.
     * Called whenever a role's permission set changes (pivot sync fires no events).
     */
    public function flushRole(Role $role): void
    {
        $role->users()->pluck('users.id')->each(fn ($id) => $this->flushUser((int) $id));
    }

    public function flushAll(): void
    {
        User::query()->pluck('id')->each(fn ($id) => $this->flushUser((int) $id));
    }
}
