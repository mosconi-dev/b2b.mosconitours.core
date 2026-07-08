<?php

namespace Tests\Concerns;

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
     * A user whose single role grants exactly the given permissions.
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
}
