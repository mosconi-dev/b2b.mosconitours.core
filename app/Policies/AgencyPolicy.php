<?php

namespace App\Policies;

use App\Models\Agency;
use App\Models\User;

/**
 * Per-instance authorization for agencies. Global invariants (an agency with
 * members cannot be deleted) live in AgencyService.
 */
class AgencyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('agency.view');
    }

    public function view(User $user, Agency $agency): bool
    {
        return $user->can('agency.view') && $this->inScope($user, $agency);
    }

    public function create(User $user): bool
    {
        return $user->can('agency.create');
    }

    public function update(User $user, Agency $agency): bool
    {
        return $user->can('agency.update') && $this->inScope($user, $agency);
    }

    /**
     * Deactivating your own agency would lock you (and everyone there) out. Combined
     * with the scope check this means an agency member can never toggle any agency:
     * their own is blocked here, everyone else's by scope.
     */
    public function toggleActive(User $user, Agency $agency): bool
    {
        return $user->can('agency.update')
            && $user->agency_id !== $agency->id
            && $this->inScope($user, $agency);
    }

    public function delete(User $user, Agency $agency): bool
    {
        return $user->can('agency.delete')
            && $user->agency_id !== $agency->id
            && $this->inScope($user, $agency);
    }

    /**
     * Platform staff see every agency; an agency member only ever their own.
     */
    private function inScope(User $user, Agency $agency): bool
    {
        return $user->isPlatformStaff() || $user->agency_id === $agency->id;
    }
}
