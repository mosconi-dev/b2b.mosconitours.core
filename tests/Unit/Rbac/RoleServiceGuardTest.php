<?php

namespace Tests\Unit\Rbac;

use App\Exceptions\RbacException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Rbac\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class RoleServiceGuardTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    private function service(): RoleService
    {
        return app(RoleService::class);
    }

    public function test_cannot_delete_a_system_role(): void
    {
        $this->expectException(RbacException::class);

        $this->service()->delete(Role::where('name', 'admin')->first());
    }

    public function test_duplicate_copies_permissions_and_is_not_system(): void
    {
        $itp = Role::where('name', 'itp')->first();

        $copy = $this->service()->duplicate($itp, 'ITP Copy');

        $this->assertFalse($copy->is_system);
        $this->assertNotSame($itp->name, $copy->name);
        $this->assertEqualsCanonicalizing(
            $itp->permissions->pluck('id')->all(),
            $copy->permissions()->pluck('permissions.id')->all(),
        );
    }

    public function test_cannot_remove_admin_permission_from_the_only_admin_role(): void
    {
        $admin = Role::where('name', 'admin')->first();
        User::factory()->create()->roles()->attach($admin->id);

        $without = Permission::where('name', '!=', 'role.update')->pluck('id')->all();

        $this->expectException(RbacException::class);
        $this->service()->syncPermissions($admin, $without);
    }

    public function test_cannot_delete_the_only_role_granting_admin(): void
    {
        // A non-system role that is the sole admin source (the seeded admin role has no members).
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::where('name', 'role.update')->value('id'));
        User::factory()->create()->roles()->attach($role->id);

        $this->expectException(RbacException::class);
        $this->service()->delete($role);
    }

    public function test_can_remove_admin_permission_when_another_admin_role_exists(): void
    {
        $admin = Role::where('name', 'admin')->first();
        User::factory()->create()->roles()->attach($admin->id);

        // A second admin-capable role held by an active user.
        $second = Role::factory()->create();
        $second->permissions()->attach(Permission::where('name', 'role.update')->value('id'));
        User::factory()->create()->roles()->attach($second->id);

        $without = Permission::where('name', '!=', 'role.update')->pluck('id')->all();
        $this->service()->syncPermissions($admin, $without);

        $this->assertFalse($admin->fresh()->permissions->contains('name', 'role.update'));
    }
}
