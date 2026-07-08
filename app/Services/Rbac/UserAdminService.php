<?php

namespace App\Services\Rbac;

use App\Exceptions\RbacException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Orchestrates admin-side user management: creation, role assignment,
 * activation, password reset and (soft) deletion — enforcing the lockout
 * guards and recording an audit trail.
 *
 * "Admin-capable" means holding an active role that grants role.update.
 */
class UserAdminService
{
    public function __construct(
        private readonly RbacCache $cache,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string}  $data
     * @param  array<int, int>  $roleIds
     */
    public function create(array $data, array $roleIds = []): User
    {
        $user = new User;
        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'], // 'hashed' cast
            'is_active' => true,
        ]);
        // Admin-provisioned accounts are pre-verified so the `verified` middleware passes.
        $user->email_verified_at = now();
        $user->save();

        $user->roles()->sync($roleIds);
        $this->cache->flushUser($user);
        $this->audit->log('user.created', $user, ['roles' => $roleIds]);

        return $user;
    }

    /**
     * @param  array{name?: string, email?: string}  $data
     * @param  array<int, int>  $roleIds
     */
    public function update(User $user, array $data, array $roleIds = []): User
    {
        $user->fill($data)->save();
        $this->applyRoles($user, $roleIds);
        $this->audit->log('user.updated', $user, ['roles' => $roleIds]);

        return $user;
    }

    /**
     * @param  array<int, int>  $roleIds
     */
    public function syncRoles(User $user, array $roleIds): void
    {
        $this->applyRoles($user, $roleIds);
        $this->audit->log('user.roles_updated', $user, ['roles' => $roleIds]);
    }

    public function toggleActive(User $user): User
    {
        if ($user->is_active) {
            $this->guardLastAdmin($user);
        }

        $user->is_active = ! $user->is_active;
        $user->save();

        $this->cache->flushUser($user);
        $this->audit->log($user->is_active ? 'user.activated' : 'user.deactivated', $user);

        return $user;
    }

    public function resetPassword(User $user, string $password): void
    {
        $user->password = $password; // 'hashed' cast
        $user->setRememberToken(Str::random(60));
        $user->save();

        $this->audit->log('user.password_reset', $user);
    }

    public function delete(User $user): void
    {
        $this->guardLastAdmin($user);

        $user->delete(); // soft delete — preserves history
        $this->cache->flushUser($user);
        $this->audit->log('user.deleted', $user);
    }

    /**
     * Number of active users who can administer roles (role.update).
     */
    public function adminCapableCount(): int
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles.permissions', fn ($q) => $q->where('name', 'role.update'))
            ->count();
    }

    /**
     * @param  array<int, int>  $roleIds
     */
    private function applyRoles(User $user, array $roleIds): void
    {
        $this->guardRoleChangeKeepsAdmin($user, $roleIds);

        $user->roles()->sync($roleIds);
        $user->forgetPermissionCache();
        $this->cache->flushUser($user);
    }

    private function isAdminCapable(User $user): bool
    {
        return $user->is_active && $user->roles()
            ->whereHas('permissions', fn ($q) => $q->where('name', 'role.update'))
            ->exists();
    }

    private function guardLastAdmin(User $user): void
    {
        if ($this->isAdminCapable($user) && $this->adminCapableCount() <= 1) {
            throw new RbacException('You cannot deactivate or delete the last administrator.');
        }
    }

    /**
     * @param  array<int, int>  $roleIds
     */
    private function guardRoleChangeKeepsAdmin(User $user, array $roleIds): void
    {
        if (! $this->isAdminCapable($user)) {
            return;
        }

        $stillAdmin = Role::whereIn('id', $roleIds)
            ->whereHas('permissions', fn ($q) => $q->where('name', 'role.update'))
            ->exists();

        if (! $stillAdmin && $this->adminCapableCount() <= 1) {
            throw new RbacException('You cannot remove administrator access from the last administrator.');
        }
    }
}
