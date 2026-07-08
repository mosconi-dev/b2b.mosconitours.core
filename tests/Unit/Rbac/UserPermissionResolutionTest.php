<?php

namespace Tests\Unit\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Rbac\RbacCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPermissionResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function permission(string $name): Permission
    {
        [$module, $action] = array_pad(explode('.', $name, 2), 2, '');

        return Permission::create([
            'name' => $name,
            'module' => $module,
            'action' => $action,
            'label' => $name,
        ]);
    }

    public function test_permission_names_are_the_deduped_union_across_roles(): void
    {
        $user = User::factory()->create();

        $roleA = Role::factory()->create();
        $roleA->permissions()->attach([
            $this->permission('flight.view')->id,
            $this->permission('flight.search')->id,
        ]);

        $roleB = Role::factory()->create();
        $roleB->permissions()->attach([
            $this->permission('user.view')->id,
        ]);
        // Overlapping permission — must be deduped in the union.
        $roleB->permissions()->attach(Permission::where('name', 'flight.search')->value('id'));

        $user->roles()->attach([$roleA->id, $roleB->id]);

        $names = $user->permissionNames();
        sort($names);

        $this->assertSame(['flight.search', 'flight.view', 'user.view'], $names);
    }

    public function test_has_permission_to_reflects_only_assigned_permissions(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach($this->permission('role.update')->id);
        $user->roles()->attach($role->id);

        $this->assertTrue($user->hasPermissionTo('role.update'));
        $this->assertFalse($user->hasPermissionTo('role.delete'));
    }

    public function test_user_with_no_roles_has_no_permissions(): void
    {
        $user = User::factory()->create();

        $this->assertSame([], $user->permissionNames());
        $this->assertFalse($user->hasPermissionTo('flight.view'));
    }

    public function test_permission_cache_is_stale_until_flushed(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach($this->permission('user.view')->id);
        $user->roles()->attach($role->id);

        // First resolution caches ['user.view'].
        $this->assertTrue($user->hasPermissionTo('user.view'));

        // Directly change the pivot — no model events fire, so the cache is now stale.
        $role->permissions()->attach($this->permission('user.create')->id);
        $this->assertFalse($user->fresh()->hasPermissionTo('user.create'));

        // Explicit flush re-resolves from the database.
        app(RbacCache::class)->flushRole($role);
        $this->assertTrue($user->fresh()->hasPermissionTo('user.create'));
    }
}
