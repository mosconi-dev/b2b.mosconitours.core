<?php

namespace App\Policies;

use App\Models\User;

/**
 * Per-instance authorization for users. Permission gates are combined with
 * self-action guards (an admin cannot delete or deactivate their own account).
 * The last-active-admin guard lives in UserAdminService.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('user.view');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('user.view');
    }

    public function create(User $user): bool
    {
        return $user->can('user.create');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('user.update');
    }

    public function toggleActive(User $user, User $model): bool
    {
        return $user->can('user.update') && $user->id !== $model->id;
    }

    public function resetPassword(User $user, User $model): bool
    {
        return $user->can('user.update');
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can('user.delete') && $user->id !== $model->id;
    }
}
