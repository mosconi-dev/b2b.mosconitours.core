<?php

namespace Tests\Unit\Rbac;

use App\Models\Permission;
use App\Services\Rbac\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionRegistrySyncTest extends TestCase
{
    use RefreshDatabase;

    private function withModules(array $modules): void
    {
        config([
            'rbac.modules' => $modules,
            'rbac.action_labels' => ['view' => 'View', 'search' => 'Search'],
        ]);
    }

    public function test_sync_upserts_all_module_permissions_idempotently(): void
    {
        $this->withModules([
            'flight' => ['label' => 'Flights', 'actions' => ['view', 'search']],
            'user' => ['label' => 'Users', 'actions' => ['view']],
        ]);

        $result = (new PermissionRegistry)->sync();

        $this->assertSame(3, $result['synced']);
        $this->assertDatabaseCount('permissions', 3);
        $this->assertDatabaseHas('permissions', [
            'name' => 'flight.search', 'module' => 'flight', 'action' => 'search',
        ]);

        // Running again must not duplicate rows.
        (new PermissionRegistry)->sync();
        $this->assertDatabaseCount('permissions', 3);
    }

    public function test_orphans_are_reported_without_prune_and_removed_with_prune(): void
    {
        Permission::create(['name' => 'legacy.remove', 'module' => 'legacy', 'action' => 'remove', 'label' => 'Legacy']);
        $this->withModules(['flight' => ['label' => 'Flights', 'actions' => ['view']]]);

        $reported = (new PermissionRegistry)->sync();
        $this->assertContains('legacy.remove', $reported['orphans']);
        $this->assertFalse($reported['pruned']);
        $this->assertDatabaseHas('permissions', ['name' => 'legacy.remove']);

        $pruned = (new PermissionRegistry)->sync(prune: true);
        $this->assertTrue($pruned['pruned']);
        $this->assertDatabaseMissing('permissions', ['name' => 'legacy.remove']);
    }

    public function test_disabled_modules_sync_rows_but_are_excluded_from_gate_names(): void
    {
        $this->withModules([
            'flight' => ['label' => 'Flights', 'actions' => ['view'], 'enabled' => true],
            'hotel' => ['label' => 'Hotels', 'actions' => ['view'], 'enabled' => false],
        ]);

        $registry = new PermissionRegistry;
        $registry->sync();

        // Rows for the disabled module still exist (so assignments survive a toggle)...
        $this->assertDatabaseHas('permissions', ['name' => 'hotel.view']);

        // ...but its ability is not exposed to the gate loop.
        $this->assertContains('flight.view', $registry->permissionNames());
        $this->assertNotContains('hotel.view', $registry->permissionNames());
        $this->assertFalse($registry->enabled('hotel'));
    }
}
