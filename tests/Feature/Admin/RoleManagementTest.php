<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_index_requires_role_view_permission(): void
    {
        $this->actingAs($this->userWith(['user.view']))
            ->get(route('admin.roles.index'))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('admin.roles.index'))
            ->assertOk();
    }

    public function test_admin_can_create_a_role_with_a_slug_name(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.roles.store'), ['name' => 'Sales Manager', 'description' => 'Sales lead'])
            ->assertRedirect();

        $role = Role::where('label', 'Sales Manager')->first();
        $this->assertNotNull($role);
        $this->assertSame('sales-manager', $role->name);
        $this->assertFalse($role->is_system);
    }

    public function test_admin_can_rename_a_role(): void
    {
        $role = Role::factory()->create(['label' => 'Old']);

        $this->actingAs($this->admin())
            ->put(route('admin.roles.update', $role), ['name' => 'Renamed', 'description' => 'x'])
            ->assertRedirect();

        $this->assertSame('Renamed', $role->fresh()->label);
    }

    public function test_admin_can_sync_permissions(): void
    {
        $role = Role::factory()->create();
        $ids = Permission::whereIn('name', ['flight.view', 'flight.search'])->pluck('id')->all();

        $this->actingAs($this->admin())
            ->put(route('admin.roles.permissions', $role), ['permissions' => $ids])
            ->assertRedirect();

        $this->assertEqualsCanonicalizing($ids, $role->fresh()->permissions->pluck('id')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'role.permissions_updated', 'auditable_id' => $role->id]);
    }

    public function test_admin_can_duplicate_a_role_copying_permissions(): void
    {
        $itp = Role::where('name', 'itp')->first();

        $this->actingAs($this->admin())
            ->post(route('admin.roles.duplicate', $itp), ['name' => 'ITP Copy'])
            ->assertRedirect();

        $copy = Role::where('label', 'ITP Copy')->first();
        $this->assertNotNull($copy);
        $this->assertEqualsCanonicalizing(
            $itp->permissions->pluck('id')->all(),
            $copy->permissions->pluck('id')->all(),
        );
    }

    public function test_admin_can_soft_delete_a_custom_role(): void
    {
        $role = Role::factory()->create();

        $this->actingAs($this->admin())
            ->delete(route('admin.roles.destroy', $role))
            ->assertRedirect(route('admin.roles.index'));

        $this->assertSoftDeleted($role);
    }

    public function test_system_role_cannot_be_deleted(): void
    {
        $admin = Role::where('name', 'admin')->first();

        $this->actingAs($this->admin())
            ->delete(route('admin.roles.destroy', $admin))
            ->assertForbidden();

        $this->assertNotSoftDeleted($admin);
    }

    public function test_edit_page_renders_the_permission_grid(): void
    {
        $role = Role::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('admin.roles.edit', $role))
            ->assertOk()
            ->assertSee('Permissions')
            ->assertSee('Administration');
    }
}
