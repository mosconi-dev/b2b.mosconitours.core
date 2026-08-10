<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Per-instance authorization for roles. The coarse permission gate (e.g.
 * "role.update") is combined with model-specific invariants (system roles are
 * protected). Global invariants such as the last-admin guard live in RoleService.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('role.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('role.view') && $this->inScope($user, $role);
    }

    public function create(User $user): bool
    {
        return $user->can('role.create');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can('role.update') && $this->inScope($user, $role);
    }

    public function duplicate(User $user, Role $role): bool
    {
        return $user->can('role.create') && $this->inScope($user, $role);
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can('role.delete') && ! $role->is_system && $this->inScope($user, $role);
    }

    /**
     * Platform staff manage every role; an agency member manages only the roles
     * their own agency owns — never another agency's, and never a platform role.
     */
    private function inScope(User $user, Role $role): bool
    {
        return $user->isPlatformStaff() || $role->agency_id === $user->agency_id;
    }
}
