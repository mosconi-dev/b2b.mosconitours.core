<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class AdminSectionTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_dashboard_requires_admin_access(): void
    {
        $this->actingAs($this->userWith(['flight.view']))
            ->get(route('admin.dashboard'))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Administration');
    }

    public function test_audit_logs_index_requires_audit_view(): void
    {
        AuditLog::create(['event' => 'user.created', 'created_at' => now()]);

        $this->actingAs($this->userWith(['user.view']))
            ->get(route('admin.audit-logs.index'))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->assertSee('User Created');
    }

    public function test_settings_index_requires_setting_view(): void
    {
        $this->actingAs($this->userWith(['user.view']))
            ->get(route('admin.settings.index'))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('admin.settings.index'))
            ->assertOk();
    }
}
