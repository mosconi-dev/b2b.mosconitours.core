<?php

namespace App\Services\Rbac;

use App\Exceptions\RbacException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Role lifecycle + permission assignment, enforcing the system-role and
 * last-admin-role guards, flushing affected users' permission caches, and
 * recording an audit trail. The "admin" capability is the role.update ability.
 */
class RoleService
{
    private const ADMIN_ABILITY = 'role.update';

    public function __construct(
        private readonly RbacCache $cache,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, description?: string|null}  $data
     * @param  array<int, int>  $permissionIds
     */
    public function create(array $data, array $permissionIds = []): Role
    {
        $role = new Role;
        $role->name = $this->uniqueName($data['name']);
        $role->label = $data['name'];
        $role->description = $data['description'] ?? null;
        $role->is_system = false;
        $role->save();

        $this->syncPermissions($role, $permissionIds, audit: false);
        $this->audit->log('role.created', $role);

        return $role;
    }

    /**
     * Rename: the display label and description are editable; the machine name is
     * immutable (protects code/seeder references, especially for system roles).
     *
     * @param  array{name: string, description?: string|null}  $data
     */
    public function update(Role $role, array $data): Role
    {
        $role->label = $data['name'];
        $role->description = $data['description'] ?? null;
        $role->save();

        $this->audit->log('role.updated', $role);

        return $role;
    }

    public function duplicate(Role $role, string $newLabel): Role
    {
        $copy = new Role;
        $copy->name = $this->uniqueName($newLabel);
        $copy->label = $newLabel;
        $copy->description = $role->description;
        $copy->is_system = false;
        $copy->save();

        $copy->permissions()->sync($role->permissions()->pluck('permissions.id'));
        $this->audit->log('role.duplicated', $copy, ['source_id' => $role->id]);

        return $copy;
    }

    /**
     * @param  array<int, int>  $permissionIds
     */
    public function syncPermissions(Role $role, array $permissionIds, bool $audit = true): void
    {
        $this->guardKeepsAnAdmin($role, $permissionIds);

        $role->permissions()->sync($permissionIds);
        $this->cache->flushRole($role);

        if ($audit) {
            $this->audit->log('role.permissions_updated', $role, ['count' => count($permissionIds)]);
        }
    }

    public function delete(Role $role): void
    {
        if ($role->is_system) {
            throw new RbacException('Built-in roles cannot be deleted.');
        }

        $this->guardKeepsAnAdmin($role, []);

        $members = $role->users()->pluck('users.id')->all();
        $role->permissions()->detach();
        $role->users()->detach();
        $role->delete(); // soft delete

        foreach ($members as $id) {
            $this->cache->flushUser((int) $id);
        }

        $this->audit->log('role.deleted', $role);
    }

    /**
     * Ensure the pending change to this role does not remove the last route to
     * administrator access (role.update) for all active users.
     *
     * @param  array<int, int>  $permissionIds  the role's permission set after the change ([] for deletion)
     */
    private function guardKeepsAnAdmin(Role $role, array $permissionIds): void
    {
        if (! $this->roleGrantsAdmin($role)) {
            return; // this role isn't an admin source — nothing to protect
        }

        $adminId = Permission::where('name', self::ADMIN_ABILITY)->value('id');
        $willStillGrant = in_array((int) $adminId, array_map('intval', $permissionIds), true);

        if ($willStillGrant) {
            return; // the role keeps role.update
        }

        if ($this->adminCountExcludingRole($role) === 0) {
            throw new RbacException('This is the only role granting administrator access — it cannot lose it.');
        }
    }

    private function roleGrantsAdmin(Role $role): bool
    {
        return $role->permissions()->where('name', self::ADMIN_ABILITY)->exists();
    }

    private function adminCountExcludingRole(Role $role): int
    {
        return User::where('is_active', true)
            ->whereHas('roles', function ($q) use ($role) {
                $q->where('roles.id', '!=', $role->id)
                    ->whereHas('permissions', fn ($p) => $p->where('name', self::ADMIN_ABILITY));
            })
            ->count();
    }

    private function uniqueName(string $label): string
    {
        $base = Str::slug($label) ?: 'role';
        $name = $base;
        $i = 2;

        while (Role::withTrashed()->where('name', $name)->exists()) {
            $name = "{$base}-{$i}";
            $i++;
        }

        return $name;
    }
}
