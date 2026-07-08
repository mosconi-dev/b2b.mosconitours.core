<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Rbac\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GateResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_gates_grant_only_assigned_permissions_and_never_bypass(): void
    {
        app(PermissionRegistry::class)->sync();

        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::where('name', 'flight.view')->value('id'));
        $user->roles()->attach($role->id);

        $this->assertTrue($user->can('flight.view'));
        $this->assertFalse($user->can('user.view'));
        $this->assertFalse($user->can('role.delete'));
    }

    public function test_disabled_module_ability_is_denied_even_if_assigned(): void
    {
        app(PermissionRegistry::class)->sync();

        // supplier.amadeus is disabled in the registry: its rows still sync...
        $amadeus = Permission::where('name', 'supplier.amadeus.view')->first();
        $this->assertNotNull($amadeus);

        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach($amadeus->id);
        $user->roles()->attach($role->id);

        // ...but no gate is defined for a disabled module, so can() denies.
        $this->assertFalse($user->can('supplier.amadeus.view'));
    }
}
