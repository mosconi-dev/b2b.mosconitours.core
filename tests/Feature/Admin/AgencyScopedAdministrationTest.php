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
 * An agency runs itself: it defines its own roles, sets their permissions, and
 * creates its own users — without ever seeing or touching another agency's.
 */
class AgencyScopedAdministrationTest extends TestCase
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

    /**
     * An agency administrator: manages roles and users, and can search flights.
     */
    private function acmeAdmin(): User
    {
        return $this->agencyUserWith($this->acme, [
            'role.view', 'role.create', 'role.update', 'role.delete',
            'user.view', 'user.create', 'user.update', 'user.delete',
            'flight.view', 'flight.search',
        ]);
    }

    // ---- Roles ----------------------------------------------------------

    public function test_agency_admin_sees_only_their_own_agency_roles(): void
    {
        $mine = Role::factory()->create(['agency_id' => $this->acme->id, 'label' => 'Acme Counter Agent']);
        $theirs = Role::factory()->create(['agency_id' => $this->rival->id, 'label' => 'Rival Counter Agent']);

        // Asserted on the view's collection rather than rendered strings: role labels
        // come from fake()->jobTitle(), which can incidentally contain any word.
        $visible = $this->actingAs($this->acmeAdmin())
            ->get(route('admin.roles.index'))
            ->assertOk()
            ->viewData('roles');

        $this->assertTrue($visible->contains($mine->id));
        $this->assertFalse($visible->contains($theirs->id));
        $this->assertTrue(
            $visible->every(fn (Role $r): bool => $r->agency_id === $this->acme->id),
            'no platform-level or foreign-agency role may appear',
        );
    }

    public function test_agency_admin_creates_roles_owned_by_their_agency(): void
    {
        $this->actingAs($this->acmeAdmin())
            ->post(route('admin.roles.store'), ['name' => 'Counter Agent'])
            ->assertRedirect();

        $role = Role::where('label', 'Counter Agent')->first();
        $this->assertSame($this->acme->id, $role->agency_id);
        $this->assertSame('acme.counter-agent', $role->name);
    }

    public function test_a_forged_agency_id_cannot_plant_a_role_in_another_agency(): void
    {
        $this->actingAs($this->acmeAdmin())
            ->post(route('admin.roles.store'), [
                'name' => 'Trojan',
                'agency_id' => $this->rival->id,
            ])
            ->assertRedirect();

        $this->assertSame($this->acme->id, Role::where('label', 'Trojan')->value('agency_id'));
    }

    public function test_two_agencies_can_both_have_a_role_of_the_same_name(): void
    {
        $this->actingAs($this->acmeAdmin())
            ->post(route('admin.roles.store'), ['name' => 'Agent'])->assertRedirect();

        $rivalAdmin = $this->agencyUserWith($this->rival, ['role.create', 'role.update']);
        $this->actingAs($rivalAdmin)
            ->post(route('admin.roles.store'), ['name' => 'Agent'])->assertRedirect();

        $this->assertSame('acme.agent', Role::where('agency_id', $this->acme->id)->where('label', 'Agent')->value('name'));
        $this->assertSame('rival.agent', Role::where('agency_id', $this->rival->id)->where('label', 'Agent')->value('name'));
    }

    public function test_agency_admin_cannot_open_another_agencys_role(): void
    {
        $theirs = Role::factory()->create(['agency_id' => $this->rival->id]);

        $this->actingAs($this->acmeAdmin())
            ->get(route('admin.roles.edit', $theirs))
            ->assertForbidden();
    }

    public function test_agency_admin_cannot_open_a_platform_role(): void
    {
        $platformRole = Role::where('name', 'itp')->first();

        $this->actingAs($this->acmeAdmin())
            ->get(route('admin.roles.edit', $platformRole))
            ->assertForbidden();
    }

    // ---- The permission ceiling -----------------------------------------

    public function test_permission_grid_is_capped_to_what_the_agency_admin_holds(): void
    {
        // Explicit label: the factory's fake()->jobTitle() could otherwise contain
        // whichever module name the assertions below look for.
        $role = Role::factory()->create(['agency_id' => $this->acme->id, 'label' => 'Grid Subject']);

        $this->actingAs($this->acmeAdmin())
            ->get(route('admin.roles.edit', $role))
            ->assertOk()
            ->assertSee('Flights')       // they hold flight.*
            ->assertDontSee('Agencies'); // they do not hold agency.*
    }

    public function test_agency_admin_cannot_grant_a_permission_they_do_not_hold(): void
    {
        $role = Role::factory()->create(['agency_id' => $this->acme->id]);
        $forbidden = Permission::where('name', 'agency.delete')->value('id');

        $this->actingAs($this->acmeAdmin())
            ->put(route('admin.roles.permissions', $role), ['permissions' => [$forbidden]])
            ->assertSessionHasErrors('rbac');

        $this->assertCount(0, $role->fresh()->permissions);
    }

    public function test_agency_admin_can_grant_a_permission_they_do_hold(): void
    {
        $role = Role::factory()->create(['agency_id' => $this->acme->id]);
        $allowed = Permission::where('name', 'flight.search')->value('id');

        $this->actingAs($this->acmeAdmin())
            ->put(route('admin.roles.permissions', $role), ['permissions' => [$allowed]])
            ->assertRedirect();

        $this->assertTrue($role->fresh()->permissions->contains('name', 'flight.search'));
    }

    public function test_saving_the_grid_preserves_grants_the_agency_admin_cannot_see(): void
    {
        // Platform staff gave this agency role a permission the agency admin lacks.
        $role = Role::factory()->create(['agency_id' => $this->acme->id]);
        $role->permissions()->attach(
            Permission::whereIn('name', ['flight.search', 'booking.refund'])->pluck('id')
        );

        // The admin's capped grid never showed booking.refund, so their save omits it.
        $this->actingAs($this->acmeAdmin())
            ->put(route('admin.roles.permissions', $role), [
                'permissions' => [Permission::where('name', 'flight.search')->value('id')],
            ])
            ->assertRedirect();

        $names = $role->fresh()->permissions->pluck('name');
        $this->assertTrue($names->contains('flight.search'));
        $this->assertTrue($names->contains('booking.refund'), 'the unseen grant must survive the save');
    }

    public function test_the_unmanageable_grants_are_surfaced_on_the_edit_page(): void
    {
        $role = Role::factory()->create(['agency_id' => $this->acme->id]);
        $role->permissions()->attach(Permission::where('name', 'booking.refund')->value('id'));

        $this->actingAs($this->acmeAdmin())
            ->get(route('admin.roles.edit', $role))
            ->assertOk()
            ->assertSee('Granted beyond your own access');
    }

    public function test_platform_staff_are_not_capped(): void
    {
        $role = Role::factory()->create(['agency_id' => $this->acme->id]);
        $any = Permission::where('name', 'agency.delete')->value('id');

        $this->actingAs($this->admin())
            ->put(route('admin.roles.permissions', $role), ['permissions' => [$any]])
            ->assertRedirect();

        $this->assertTrue($role->fresh()->permissions->contains('name', 'agency.delete'));
    }

    // ---- Users ----------------------------------------------------------

    public function test_agency_admin_sees_only_their_own_agency_users(): void
    {
        $mine = User::factory()->create(['agency_id' => $this->acme->id, 'name' => 'Mine Person']);
        $theirs = User::factory()->create(['agency_id' => $this->rival->id, 'name' => 'Theirs Person']);
        $platform = User::factory()->create(['name' => 'Platform Person']);

        $this->actingAs($this->acmeAdmin())
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee($mine->name)
            ->assertDontSee($theirs->name)
            ->assertDontSee($platform->name);
    }

    public function test_agency_admin_creates_users_inside_their_own_agency(): void
    {
        $this->actingAs($this->acmeAdmin())
            ->post(route('admin.users.store'), [
                'name' => 'New Agent',
                'email' => 'new.agent@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                // Forged: they try to place the user elsewhere.
                'agency_id' => $this->rival->id,
            ])
            ->assertRedirect(route('admin.users.index'));

        $this->assertSame($this->acme->id, User::where('email', 'new.agent@example.com')->value('agency_id'));
    }

    public function test_agency_admin_cannot_edit_a_user_in_another_agency(): void
    {
        $theirs = User::factory()->create(['agency_id' => $this->rival->id]);
        $admin = $this->acmeAdmin();

        $this->actingAs($admin)->get(route('admin.users.edit', $theirs))->assertForbidden();
        $this->actingAs($admin)->patch(route('admin.users.toggle-active', $theirs))->assertForbidden();
        $this->actingAs($admin)->delete(route('admin.users.destroy', $theirs))->assertForbidden();
    }

    public function test_agency_admin_cannot_edit_platform_staff(): void
    {
        $platform = User::factory()->create();

        $this->actingAs($this->acmeAdmin())
            ->get(route('admin.users.edit', $platform))
            ->assertForbidden();
    }

    public function test_a_user_cannot_be_given_a_role_from_another_agency(): void
    {
        $mine = User::factory()->create(['agency_id' => $this->acme->id]);
        $theirRole = Role::factory()->create(['agency_id' => $this->rival->id]);

        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $mine), [
                'name' => $mine->name,
                'email' => $mine->email,
                'agency_id' => $this->acme->id,
                'roles' => [$theirRole->id],
            ])
            ->assertSessionHasErrors('rbac');

        $this->assertCount(0, $mine->fresh()->roles);
    }

    public function test_moving_a_user_between_agencies_drops_their_old_roles(): void
    {
        $acmeRole = Role::factory()->create(['agency_id' => $this->acme->id]);
        $user = User::factory()->create(['agency_id' => $this->acme->id]);
        $user->roles()->attach($acmeRole->id);

        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $user), [
                'name' => $user->name,
                'email' => $user->email,
                'agency_id' => $this->rival->id,
                'roles' => [$acmeRole->id], // the form still carries the old agency's role
            ])
            ->assertRedirect(route('admin.users.index'));

        $user->refresh();
        $this->assertSame($this->rival->id, $user->agency_id);
        $this->assertCount(0, $user->roles, 'roles from the previous agency must not follow the user');
    }

    public function test_the_last_admin_guard_is_per_agency(): void
    {
        // Acme's only admin-capable user. Deactivating them must be refused even
        // though the platform still has its own administrators.
        $acmeAdmin = $this->agencyUserWith($this->acme, ['role.update', 'user.update']);
        $this->admin(); // a platform admin exists

        $platformOperator = $this->userWith(['user.view', 'user.update']);

        $this->actingAs($platformOperator)
            ->patch(route('admin.users.toggle-active', $acmeAdmin))
            ->assertSessionHasErrors('rbac');

        $this->assertTrue($acmeAdmin->refresh()->is_active);
    }

    public function test_an_agency_admin_is_not_the_last_admin_of_another_agency(): void
    {
        // Rival has its own admin, so Acme deactivating one of its two is fine.
        $this->agencyUserWith($this->rival, ['role.update']);
        $first = $this->agencyUserWith($this->acme, ['role.update']);
        $this->agencyUserWith($this->acme, ['role.update']);

        $this->actingAs($this->admin())
            ->patch(route('admin.users.toggle-active', $first))
            ->assertRedirect();

        $this->assertFalse($first->refresh()->is_active);
    }

    // ---- Rejected writes leave nothing behind ----------------------------

    public function test_a_rejected_role_creation_persists_nothing(): void
    {
        // The ceiling guard fires inside create(), after the role row would have been
        // written — the whole thing must roll back rather than leave an orphan.
        // Baseline is taken after the actor exists: building them creates a role too.
        $admin = $this->acmeAdmin();
        $before = Role::count();

        $this->actingAs($admin)
            ->post(route('admin.roles.store'), [
                'name' => 'Overreaching Role',
                'permissions' => [Permission::where('name', 'agency.delete')->value('id')],
            ])
            ->assertSessionHasErrors('rbac');

        $this->assertDatabaseMissing('roles', ['label' => 'Overreaching Role']);
        $this->assertSame($before, Role::count());
    }

    public function test_a_rejected_user_creation_persists_nothing(): void
    {
        // A role-less account would still be a usable login, so the row must not survive.
        $theirRole = Role::factory()->create(['agency_id' => $this->rival->id]);

        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Half Built',
                'email' => 'half.built@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'agency_id' => $this->acme->id,
                'roles' => [$theirRole->id],
            ])
            ->assertSessionHasErrors('rbac');

        $this->assertDatabaseMissing('users', ['email' => 'half.built@example.com']);
    }

    public function test_a_rejected_user_update_does_not_move_the_user(): void
    {
        $user = User::factory()->create(['agency_id' => $this->acme->id]);
        $platformRole = Role::factory()->create(); // agency_id null — out of scope for an agency user

        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $user), [
                'name' => 'Renamed',
                'email' => $user->email,
                'agency_id' => $this->acme->id,
                'roles' => [$platformRole->id],
            ])
            ->assertSessionHasErrors('rbac');

        $user->refresh();
        $this->assertNotSame('Renamed', $user->name, 'the rename must roll back with the rejected roles');
        $this->assertSame($this->acme->id, $user->agency_id);
    }

    // ---- Platform staff keep the full view -------------------------------

    public function test_platform_staff_still_see_every_agency(): void
    {
        $mine = User::factory()->create(['agency_id' => $this->acme->id, 'name' => 'Acme Person']);
        $theirs = User::factory()->create(['agency_id' => $this->rival->id, 'name' => 'Rival Person']);

        $this->actingAs($this->admin())
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee($mine->name)
            ->assertSee($theirs->name);
    }
}
