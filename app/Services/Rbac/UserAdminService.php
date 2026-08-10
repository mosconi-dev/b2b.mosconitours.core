<?php

namespace App\Services\Rbac;

use App\Exceptions\RbacException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
     * @param  array{name: string, email: string, password: string, agency_id?: int|null}  $data
     * @param  array<int, int>  $roleIds
     */
    public function create(array $data, array $roleIds, User $actor): User
    {
        $agencyId = $this->resolveAgency($actor, $data['agency_id'] ?? null);

        // Transactional: applyRoles runs the scope and last-admin guards, which throw.
        // Without this the user row would already be saved and a rejected request would
        // leave a role-less account (and a usable login) behind.
        return DB::transaction(function () use ($data, $roleIds, $agencyId): User {
            $user = new User;
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'], // 'hashed' cast
                'is_active' => true,
                'agency_id' => $agencyId,
            ]);
            // Admin-provisioned accounts are pre-verified so the `verified` middleware passes.
            $user->email_verified_at = now();
            $user->save();

            $this->applyRoles($user, $roleIds);
            $this->audit->log('user.created', $user, ['roles' => $roleIds, 'agency_id' => $agencyId]);

            return $user;
        });
    }

    /**
     * Which agency a user being created or moved lands in.
     *
     * An agency member can only ever place people inside their own agency — the
     * submitted value is ignored rather than validated, so a forged form field
     * cannot move someone into (or out of) another agency. Platform staff choose
     * freely, including null for platform staff.
     */
    private function resolveAgency(User $actor, ?int $requested): ?int
    {
        return $actor->isPlatformStaff() ? $requested : $actor->agency_id;
    }

    /**
     * @param  array{name?: string, email?: string, agency_id?: int|null}  $data
     * @param  array<int, int>  $roleIds
     */
    public function update(User $user, array $data, array $roleIds, User $actor): User
    {
        // Same reasoning as create(): applyRoles can reject after the details (and a
        // possible agency move) have already been written.
        return DB::transaction(fn (): User => $this->applyUpdate($user, $data, $roleIds, $actor));
    }

    /**
     * @param  array{name?: string, email?: string, agency_id?: int|null}  $data
     * @param  array<int, int>  $roleIds
     */
    private function applyUpdate(User $user, array $data, array $roleIds, User $actor): User
    {
        $previousAgencyId = $user->agency_id;

        if (array_key_exists('agency_id', $data)) {
            $data['agency_id'] = $this->resolveAgency($actor, $data['agency_id']);
        }

        $user->fill($data)->save();

        // A role belongs to one scope, so moving a user invalidates every role they
        // held at the old agency. Those grants are dropped rather than carried over —
        // the change only ever removes access, never adds it.
        if ($user->agency_id !== $previousAgencyId) {
            $roleIds = $this->rolesWithinScope($user, $roleIds);
        }

        $this->applyRoles($user, $roleIds);

        // Moving a user between agencies changes the scope everything they do is
        // filed under, so it is recorded explicitly rather than as a plain update.
        $properties = ['roles' => $roleIds];

        if ($user->agency_id !== $previousAgencyId) {
            $properties['agency_from'] = $previousAgencyId;
            $properties['agency_to'] = $user->agency_id;
        }

        $this->audit->log('user.updated', $user, $properties);

        return $user;
    }

    /**
     * @param  array<int, int>  $roleIds
     * @return array<int, int>
     */
    private function rolesWithinScope(User $user, array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        return Role::whereIn('id', $roleIds)
            ->when(
                $user->agency_id === null,
                fn ($q) => $q->whereNull('agency_id'),
                fn ($q) => $q->where('agency_id', $user->agency_id),
            )
            ->pluck('id')
            ->all();
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
     * Number of active users who can administer roles (role.update) within one
     * scope — a single agency, or the platform scope when $agencyId is null.
     *
     * The guard is per-scope because an agency losing its last admin is recoverable
     * (platform staff can step in), whereas the platform losing its last admin is not.
     */
    public function adminCapableCount(?int $agencyId): int
    {
        return User::query()
            ->where('is_active', true)
            ->when(
                $agencyId === null,
                fn ($q) => $q->whereNull('agency_id'),
                fn ($q) => $q->where('agency_id', $agencyId),
            )
            ->whereHas('roles.permissions', fn ($q) => $q->where('name', 'role.update'))
            ->count();
    }

    /**
     * @param  array<int, int>  $roleIds
     */
    private function applyRoles(User $user, array $roleIds): void
    {
        $this->guardRolesAreInScope($user, $roleIds);
        $this->guardRoleChangeKeepsAdmin($user, $roleIds);

        $user->roles()->sync($roleIds);
        $user->forgetPermissionCache();
        $this->cache->flushUser($user);
    }

    /**
     * A user may only hold roles from their own scope. This is the invariant that
     * keeps an agency's roles from ever landing on another agency's people (or on
     * platform staff), no matter what a form submits.
     *
     * @param  array<int, int>  $roleIds
     */
    private function guardRolesAreInScope(User $user, array $roleIds): void
    {
        if ($roleIds === []) {
            return;
        }

        $outOfScope = Role::whereIn('id', $roleIds)
            ->when(
                $user->agency_id === null,
                fn ($q) => $q->whereNotNull('agency_id'),
                fn ($q) => $q->where(fn ($w) => $w->whereNull('agency_id')->orWhere('agency_id', '!=', $user->agency_id)),
            )
            ->pluck('label');

        if ($outOfScope->isNotEmpty()) {
            throw new RbacException(
                'These roles belong to a different agency than the user: '.$outOfScope->implode(', ').'.'
            );
        }
    }

    private function isAdminCapable(User $user): bool
    {
        return $user->is_active && $user->roles()
            ->whereHas('permissions', fn ($q) => $q->where('name', 'role.update'))
            ->exists();
    }

    private function guardLastAdmin(User $user): void
    {
        if ($this->isAdminCapable($user) && $this->adminCapableCount($user->agency_id) <= 1) {
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

        if (! $stillAdmin && $this->adminCapableCount($user->agency_id) <= 1) {
            throw new RbacException('You cannot remove administrator access from the last administrator.');
        }
    }
}
