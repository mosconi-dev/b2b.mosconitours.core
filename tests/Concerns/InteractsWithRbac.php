<?php

namespace Tests\Concerns;

use App\Models\Agency;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

trait InteractsWithRbac
{
    protected function seedRbac(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'admin')->value('id'));

        return $user;
    }

    /**
     * A platform-staff user (no agency) whose single role grants exactly the given
     * permissions.
     *
     * @param  array<int, string>  $permissionNames
     */
    protected function userWith(array $permissionNames): User
    {
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::whereIn('name', $permissionNames)->pluck('id'));

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * A user inside the given agency, holding a role owned by that same agency —
     * the scope invariant every agency member must satisfy.
     *
     * @param  array<int, string>  $permissionNames
     */
    protected function agencyUserWith(Agency $agency, array $permissionNames): User
    {
        $role = Role::factory()->create(['agency_id' => $agency->id]);
        $role->permissions()->attach(Permission::whereIn('name', $permissionNames)->pluck('id'));

        $user = User::factory()->create(['agency_id' => $agency->id]);
        $user->roles()->attach($role->id);

        return $user;
    }
}
