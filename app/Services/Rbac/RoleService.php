<?php

namespace App\Services\Rbac;

use App\Exceptions\RbacException;
use App\Models\Agency;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
     * @param  array{name: string, description?: string|null, agency_id?: int|null}  $data
     * @param  array<int, int>  $permissionIds
     */
    public function create(array $data, array $permissionIds, User $actor): Role
    {
        $agencyId = $this->resolveOwner($actor, $data['agency_id'] ?? null);

        // Transactional: syncPermissions runs the ceiling and last-admin guards, which
        // throw. Without this the role row would already be saved and a rejected
        // request would leave a permission-less orphan behind.
        return DB::transaction(function () use ($data, $permissionIds, $actor, $agencyId): Role {
            $role = new Role;
            $role->agency_id = $agencyId;
            $role->name = $this->uniqueName($data['name'], $agencyId);
            $role->label = $data['name'];
            $role->description = $data['description'] ?? null;
            $role->is_system = false;
            $role->save();

            $this->syncPermissions($role, $permissionIds, $actor, audit: false);
            $this->audit->log('role.created', $role, ['agency_id' => $agencyId]);

            return $role;
        });
    }

    /**
     * Which agency owns a role the actor is creating.
     *
     * An agency member can only ever create roles inside their own agency — the
     * submitted value is ignored rather than validated, so a forged form field
     * cannot plant a role in another agency. Platform staff choose freely.
     */
    private function resolveOwner(User $actor, ?int $requested): ?int
    {
        return $actor->isPlatformStaff() ? $requested : $actor->agency_id;
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

    /**
     * The copy stays in the source's agency — duplicating never moves a role
     * between scopes, so it cannot be used to smuggle permissions across.
     */
    public function duplicate(Role $role, string $newLabel): Role
    {
        $copy = new Role;
        $copy->agency_id = $role->agency_id;
        $copy->name = $this->uniqueName($newLabel, $role->agency_id);
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
    public function syncPermissions(Role $role, array $permissionIds, User $actor, bool $audit = true): void
    {
        $permissionIds = $this->boundToCeiling($actor, $role, $permissionIds);
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
     * No-escalation rule: an agency member may only add permissions they already
     * hold themselves. Without it, an agency admin with role.create could mint a
     * role granting anything and assign it to themselves.
     *
     * Permissions already on the role that the actor cannot hold are **preserved**
     * rather than dropped — otherwise an agency admin saving the permission grid
     * would silently strip grants that platform staff had made and that their own
     * (capped) view of the grid never showed them.
     *
     * Platform staff are unbounded: they operate the whole system, and capping them
     * would make some permissions ungrantable by anyone.
     *
     * @param  array<int, int>  $submitted
     * @return array<int, int> the permission set to actually persist
     */
    private function boundToCeiling(User $actor, Role $role, array $submitted): array
    {
        if ($actor->isPlatformStaff()) {
            return $submitted;
        }

        $submitted = array_map('intval', $submitted);
        $held = Permission::whereIn('name', $actor->permissionNames())->pluck('id')
            ->map(fn ($id): int => (int) $id)->all();
        $current = $role->exists
            ? $role->permissions()->pluck('permissions.id')->map(fn ($id): int => (int) $id)->all()
            : [];

        // Asking for something the actor cannot hold and the role does not already
        // have is an escalation attempt, not a preserved value.
        $forged = array_diff($submitted, $held, $current);

        if ($forged !== []) {
            $names = Permission::whereIn('id', $forged)->pluck('name')->implode(', ');

            throw new RbacException("You can only grant permissions you hold yourself. Not yours: {$names}.");
        }

        return array_values(array_unique(array_merge(
            array_intersect($submitted, $held),   // what the actor may manage
            array_diff($current, $held),          // what they may not — left untouched
        )));
    }

    /**
     * Permissions on the role that the given actor cannot manage (outside their own
     * ceiling). Surfaced read-only in the UI so the set is never invisible.
     *
     * @return array<int, string>
     */
    public function unmanageablePermissionLabels(Role $role, User $actor): array
    {
        if ($actor->isPlatformStaff()) {
            return [];
        }

        return $role->permissions()
            ->whereNotIn('permissions.name', $actor->permissionNames())
            ->orderBy('permissions.name')
            ->pluck('permissions.label')
            ->all();
    }

    /**
     * Ensure the pending change to this role does not remove the last route to
     * administrator access (role.update) within the role's own scope.
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

    /**
     * Active users in the role's own scope (its agency, or the platform scope for a
     * platform role) who would still be admin-capable without it.
     */
    private function adminCountExcludingRole(Role $role): int
    {
        return User::where('is_active', true)
            ->when(
                $role->agency_id === null,
                fn ($q) => $q->whereNull('agency_id'),
                fn ($q) => $q->where('agency_id', $role->agency_id),
            )
            ->whereHas('roles', function ($q) use ($role) {
                $q->where('roles.id', '!=', $role->id)
                    ->whereHas('permissions', fn ($p) => $p->where('name', self::ADMIN_ABILITY));
            })
            ->count();
    }

    private function roleGrantsAdmin(Role $role): bool
    {
        return $role->permissions()->where('name', self::ADMIN_ABILITY)->exists();
    }

    /**
     * The machine name stays globally unique (it is the lookup key used by seeders
     * and tests), so an agency's roles are namespaced under its code — two agencies
     * can both call a role "Agent" without colliding.
     */
    private function uniqueName(string $label, ?int $agencyId): string
    {
        $base = Str::slug($label) ?: 'role';

        if ($agencyId !== null && $code = Agency::whereKey($agencyId)->value('code')) {
            $base = "{$code}.{$base}";
        }

        $name = $base;
        $i = 2;

        while (Role::withTrashed()->where('name', $name)->exists()) {
            $name = "{$base}-{$i}";
            $i++;
        }

        return $name;
    }
}
