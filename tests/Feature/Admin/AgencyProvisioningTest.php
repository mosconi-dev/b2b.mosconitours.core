<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Adding a user or a role from inside the agency show page. The whole flow stays
 * under /admin/agencies/{agency} — it never redirects to /admin/users or /admin/roles.
 */
class AgencyProvisioningTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    private Agency $acme;

    private Agency $rival;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();

        $this->acme = Agency::factory()->create(['code' => 'acme', 'name' => 'Acme Travel']);
        $this->rival = Agency::factory()->create(['code' => 'rival', 'name' => 'Rival Tours']);
    }

    private function acmeAdmin(): User
    {
        return $this->agencyUserWith($this->acme, [
            'agency.view',
            'role.view', 'role.create', 'role.update',
            'user.view', 'user.create', 'user.update',
            'flight.view', 'flight.search',
        ]);
    }

    // ---- The buttons are on the page ------------------------------------

    public function test_show_page_offers_new_user_and_new_role_within_the_agency(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.agencies.show', ['agency' => $this->acme, 'tab' => 'users']))
            ->assertOk()
            ->assertSee(route('admin.agencies.users.create', $this->acme), escape: false);

        $this->actingAs($admin)
            ->get(route('admin.agencies.show', ['agency' => $this->acme, 'tab' => 'roles']))
            ->assertOk()
            ->assertSee(route('admin.agencies.roles.create', $this->acme), escape: false);
    }

    // ---- Users -----------------------------------------------------------

    public function test_user_form_renders_and_posts_back_to_the_agency(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.agencies.users.create', $this->acme))
            ->assertOk()
            ->assertSee('New User')
            ->assertSee('Acme Travel')
            ->assertSee(route('admin.agencies.users.store', $this->acme), escape: false);
    }

    public function test_creating_a_user_lands_them_in_the_agency_and_returns_to_it(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.agencies.users.store', $this->acme), [
                'name' => 'Counter Staff',
                'email' => 'counter@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])
            ->assertRedirect(route('admin.agencies.show', $this->acme));

        $user = User::where('email', 'counter@example.com')->first();
        $this->assertSame($this->acme->id, $user->agency_id);
    }

    public function test_the_user_form_offers_only_this_agencys_roles(): void
    {
        $mine = Role::factory()->create(['agency_id' => $this->acme->id]);
        $theirs = Role::factory()->create(['agency_id' => $this->rival->id]);
        $platform = Role::where('name', 'itp')->first();

        $offered = $this->actingAs($this->admin())
            ->get(route('admin.agencies.users.create', $this->acme))
            ->assertOk()
            ->viewData('roles');

        $this->assertTrue($offered->contains($mine->id));
        $this->assertFalse($offered->contains($theirs->id));
        $this->assertFalse($offered->contains($platform->id));
    }

    public function test_a_user_cannot_be_created_with_another_agencys_role(): void
    {
        $theirRole = Role::factory()->create(['agency_id' => $this->rival->id]);

        $this->actingAs($this->admin())
            ->post(route('admin.agencies.users.store', $this->acme), [
                'name' => 'Trojan',
                'email' => 'trojan@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'roles' => [$theirRole->id],
            ])
            ->assertSessionHasErrors('rbac');

        $this->assertDatabaseMissing('users', ['email' => 'trojan@example.com']);
    }

    // ---- Roles -----------------------------------------------------------

    public function test_role_form_renders_with_the_permission_grid(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.agencies.roles.create', $this->acme))
            ->assertOk()
            ->assertSee('New Role')
            ->assertSee('Permissions')
            ->assertSee('Flights');
    }

    public function test_creating_a_role_sets_its_permissions_in_one_submit(): void
    {
        $ids = Permission::whereIn('name', ['flight.view', 'flight.search'])->pluck('id')->all();

        $this->actingAs($this->admin())
            ->post(route('admin.agencies.roles.store', $this->acme), [
                'name' => 'Counter Agent',
                'description' => 'Front desk',
                'permissions' => $ids,
            ])
            ->assertRedirect(route('admin.agencies.show', ['agency' => $this->acme, 'tab' => 'roles']));

        $role = Role::where('label', 'Counter Agent')->first();
        $this->assertSame($this->acme->id, $role->agency_id);
        $this->assertSame('acme.counter-agent', $role->name);
        $this->assertEqualsCanonicalizing($ids, $role->permissions->pluck('id')->all());
    }

    public function test_the_new_role_is_immediately_assignable_to_that_agencys_users(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.agencies.roles.store', $this->acme), ['name' => 'Counter Agent'])
            ->assertRedirect();

        $role = Role::where('label', 'Counter Agent')->first();

        $this->actingAs($this->admin())
            ->post(route('admin.agencies.users.store', $this->acme), [
                'name' => 'Fresh Hire',
                'email' => 'fresh@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'roles' => [$role->id],
            ])
            ->assertRedirect(route('admin.agencies.show', $this->acme));

        $this->assertTrue(User::where('email', 'fresh@example.com')->first()->roles->contains($role->id));
    }

    // ---- Scope enforcement -----------------------------------------------

    public function test_an_agency_admin_can_provision_inside_their_own_agency(): void
    {
        $admin = $this->acmeAdmin();

        $this->actingAs($admin)->get(route('admin.agencies.users.create', $this->acme))->assertOk();
        $this->actingAs($admin)->get(route('admin.agencies.roles.create', $this->acme))->assertOk();

        $this->actingAs($admin)
            ->post(route('admin.agencies.roles.store', $this->acme), ['name' => 'Junior Agent'])
            ->assertRedirect();

        $this->assertSame($this->acme->id, Role::where('label', 'Junior Agent')->value('agency_id'));
    }

    public function test_an_agency_admin_cannot_provision_into_another_agency(): void
    {
        $admin = $this->acmeAdmin();

        $this->actingAs($admin)->get(route('admin.agencies.users.create', $this->rival))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.agencies.roles.create', $this->rival))->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.agencies.users.store', $this->rival), [
                'name' => 'Infiltrator',
                'email' => 'infiltrator@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.agencies.roles.store', $this->rival), ['name' => 'Trojan Role'])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'infiltrator@example.com']);
        $this->assertDatabaseMissing('roles', ['label' => 'Trojan Role']);
    }

    public function test_the_permission_ceiling_still_applies_to_this_form(): void
    {
        $forbidden = Permission::where('name', 'agency.delete')->value('id');

        $this->actingAs($this->acmeAdmin())
            ->post(route('admin.agencies.roles.store', $this->acme), [
                'name' => 'Overreach',
                'permissions' => [$forbidden],
            ])
            ->assertSessionHasErrors('rbac');

        $this->assertDatabaseMissing('roles', ['label' => 'Overreach']);
    }

    public function test_provisioning_requires_the_create_permissions(): void
    {
        // Can view the agency, but may not create users or roles.
        $viewer = $this->agencyUserWith($this->acme, ['agency.view', 'user.view', 'role.view']);

        $this->actingAs($viewer)->get(route('admin.agencies.users.create', $this->acme))->assertForbidden();
        $this->actingAs($viewer)->get(route('admin.agencies.roles.create', $this->acme))->assertForbidden();
    }
}
