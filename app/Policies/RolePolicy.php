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
        return $user->can('role.view');
    }

    public function create(User $user): bool
    {
        return $user->can('role.create');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can('role.update');
    }

    public function duplicate(User $user, Role $role): bool
    {
        return $user->can('role.create');
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can('role.delete') && ! $role->is_system;
    }
}
