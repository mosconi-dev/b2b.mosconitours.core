<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_index_requires_user_view_permission(): void
    {
        $this->actingAs($this->userWith(['flight.view']))
            ->get(route('admin.users.index'))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('admin.users.index'))
            ->assertOk();
    }

    public function test_create_page_requires_user_create_permission(): void
    {
        $this->actingAs($this->userWith(['user.view']))
            ->get(route('admin.users.create'))
            ->assertForbidden();
    }

    public function test_create_and_edit_pages_render_for_admin(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)->get(route('admin.users.create'))->assertOk()->assertSee('Create User');
        $this->actingAs($admin)->get(route('admin.users.edit', $user))->assertOk()->assertSee('Edit User');
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Administration');
    }

    public function test_admin_can_create_a_user_with_roles(): void
    {
        $itp = Role::where('name', 'itp')->first();

        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Jane Agent',
                'email' => 'jane@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'roles' => [$itp->id],
            ])
            ->assertRedirect(route('admin.users.index'));

        $user = User::where('email', 'jane@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at); // admin-provisioned -> pre-verified
        $this->assertTrue($user->roles->contains($itp->id));
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.created', 'auditable_id' => $user->id]);
    }

    public function test_create_validates_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Dup',
                'email' => 'taken@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_admin_can_update_user_details_and_roles(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);
        $resa = Role::where('name', 'resa')->first();

        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $user), [
                'name' => 'New Name',
                'email' => $user->email,
                'roles' => [$resa->id],
            ])
            ->assertRedirect(route('admin.users.index'));

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertTrue($user->roles->contains($resa->id));
    }

    public function test_admin_can_assign_a_user_to_an_agency(): void
    {
        $agency = Agency::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Outlet Agent',
                'email' => 'outlet.agent@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'agency_id' => $agency->id,
            ])
            ->assertRedirect(route('admin.users.index'));

        $user = User::where('email', 'outlet.agent@example.com')->first();
        $this->assertSame($agency->id, $user->agency_id);
        $this->assertFalse($user->isPlatformStaff());
    }

    public function test_a_user_without_an_agency_is_platform_staff(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Platform Person',
                'email' => 'platform@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'agency_id' => '',
            ])
            ->assertRedirect(route('admin.users.index'));

        $user = User::where('email', 'platform@example.com')->first();
        $this->assertNull($user->agency_id);
        $this->assertTrue($user->isPlatformStaff());
    }

    public function test_moving_a_user_between_agencies_is_audited(): void
    {
        $from = Agency::factory()->create();
        $to = Agency::factory()->create();
        $user = User::factory()->create(['agency_id' => $from->id]);

        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $user), [
                'name' => $user->name,
                'email' => $user->email,
                'agency_id' => $to->id,
            ])
            ->assertRedirect(route('admin.users.index'));

        $this->assertSame($to->id, $user->refresh()->agency_id);

        $log = AuditLog::where('event', 'user.updated')
            ->where('auditable_id', $user->id)
            ->latest('id')
            ->first();

        $this->assertSame($from->id, $log->properties['agency_from']);
        $this->assertSame($to->id, $log->properties['agency_to']);
    }

    public function test_admin_can_reset_a_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('admin.users.password', $user), [
                'password' => 'BrandNew123!',
                'password_confirmation' => 'BrandNew123!',
            ])
            ->assertRedirect();

        $this->assertTrue(Hash::check('BrandNew123!', $user->refresh()->password));
    }

    public function test_admin_can_deactivate_a_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin())
            ->patch(route('admin.users.toggle-active', $user))
            ->assertRedirect();

        $this->assertFalse($user->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.deactivated', 'auditable_id' => $user->id]);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch(route('admin.users.toggle-active', $admin))
            ->assertForbidden();

        $this->assertTrue($admin->refresh()->is_active);
    }

    public function test_cannot_deactivate_the_last_admin(): void
    {
        $lastAdmin = $this->admin();
        // An operator who can manage users but is NOT admin-capable (no role.update).
        $operator = $this->userWith(['user.view', 'user.update']);

        $this->actingAs($operator)
            ->patch(route('admin.users.toggle-active', $lastAdmin))
            ->assertSessionHasErrors('rbac');

        $this->assertTrue($lastAdmin->refresh()->is_active);
    }

    public function test_admin_can_soft_delete_a_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin())
            ->delete(route('admin.users.destroy', $user))
            ->assertRedirect(route('admin.users.index'));

        $this->assertSoftDeleted($user);
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $admin))
            ->assertForbidden();

        $this->assertNotSoftDeleted($admin);
    }
}
