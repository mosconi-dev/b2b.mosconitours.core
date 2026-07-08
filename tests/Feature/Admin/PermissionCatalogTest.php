<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class PermissionCatalogTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_index_requires_permission_view(): void
    {
        $this->actingAs($this->userWith(['user.view']))
            ->get(route('admin.permissions.index'))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('admin.permissions.index'))
            ->assertOk()
            ->assertSee('flight.view')
            ->assertSee('Administration');
    }

    public function test_sync_recreates_missing_permissions(): void
    {
        Permission::where('name', 'flight.view')->delete();
        $this->assertDatabaseMissing('permissions', ['name' => 'flight.view']);

        $this->actingAs($this->admin())
            ->post(route('admin.permissions.sync'))
            ->assertRedirect();

        $this->assertDatabaseHas('permissions', ['name' => 'flight.view']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'permission.synced']);
    }

    public function test_sync_requires_permission_sync_ability(): void
    {
        $this->actingAs($this->userWith(['permission.view']))
            ->post(route('admin.permissions.sync'))
            ->assertForbidden();
    }
}
