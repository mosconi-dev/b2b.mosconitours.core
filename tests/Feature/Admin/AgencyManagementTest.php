<?php

namespace Tests\Feature\Admin;

use App\Enums\AgencyType;
use App\Models\Agency;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class AgencyManagementTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_index_requires_agency_view_permission(): void
    {
        $this->actingAs($this->userWith(['flight.view']))
            ->get(route('admin.agencies.index'))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('admin.agencies.index'))
            ->assertOk();
    }

    public function test_create_page_requires_agency_create_permission(): void
    {
        $this->actingAs($this->userWith(['agency.view']))
            ->get(route('admin.agencies.create'))
            ->assertForbidden();
    }

    public function test_create_and_edit_pages_render_for_admin(): void
    {
        $admin = $this->admin();
        $agency = Agency::factory()->create();

        $this->actingAs($admin)->get(route('admin.agencies.create'))->assertOk()->assertSee('Create Agency');
        $this->actingAs($admin)->get(route('admin.agencies.edit', $agency))->assertOk()->assertSee('Edit Agency');
    }

    public function test_show_page_requires_agency_view_permission(): void
    {
        $agency = Agency::factory()->create();

        $this->actingAs($this->userWith(['flight.view']))
            ->get(route('admin.agencies.show', $agency))
            ->assertForbidden();
    }

    public function test_show_page_lists_the_agencys_users(): void
    {
        $agency = Agency::factory()->create(['name' => 'Acme Travel']);
        $mine = User::factory()->create(['agency_id' => $agency->id, 'name' => 'Inside Person']);
        $elsewhere = User::factory()->create(['name' => 'Outside Person']);

        $this->actingAs($this->admin())
            ->get(route('admin.agencies.show', $agency))
            ->assertOk()
            ->assertSee('Acme Travel')
            ->assertSee($mine->name)
            ->assertDontSee($elsewhere->name);
    }

    public function test_show_page_roles_tab_lists_only_roles_the_agency_owns(): void
    {
        $agency = Agency::factory()->create();
        $owned = Role::factory()->create(['agency_id' => $agency->id, 'label' => 'Owned Counter Role']);
        $other = Role::factory()->create(['label' => 'Unrelated Platform Role']);

        $this->actingAs($this->admin())
            ->get(route('admin.agencies.show', ['agency' => $agency, 'tab' => 'roles']))
            ->assertOk()
            ->assertSee($owned->label)
            ->assertDontSee($other->label);
    }

    public function test_show_page_counts_users_and_roles(): void
    {
        $agency = Agency::factory()->create();
        User::factory()->count(3)->create(['agency_id' => $agency->id]);
        Role::factory()->count(2)->create(['agency_id' => $agency->id]);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.agencies.show', $agency))
            ->assertOk();

        $this->assertSame(3, $response->viewData('agency')->users_count);
        $this->assertSame(2, $response->viewData('agency')->roles_count);
    }

    public function test_an_agency_member_can_view_only_their_own_agency(): void
    {
        $mine = Agency::factory()->create();
        $theirs = Agency::factory()->create();
        $member = $this->agencyUserWith($mine, ['agency.view']);

        $this->actingAs($member)->get(route('admin.agencies.show', $mine))->assertOk();
        $this->actingAs($member)->get(route('admin.agencies.show', $theirs))->assertForbidden();
    }

    public function test_an_agency_member_sees_only_their_own_agency_in_the_list(): void
    {
        $mine = Agency::factory()->create(['name' => 'Mine Travel']);
        $theirs = Agency::factory()->create(['name' => 'Theirs Tours']);
        $member = $this->agencyUserWith($mine, ['agency.view']);

        $listed = $this->actingAs($member)
            ->get(route('admin.agencies.index'))
            ->assertOk()
            ->viewData('agencies');

        $this->assertTrue($listed->contains($mine->id));
        $this->assertFalse($listed->contains($theirs->id), 'the partner network must not be enumerable');
    }

    public function test_the_reports_to_dropdown_does_not_leak_other_agencies(): void
    {
        $mine = Agency::factory()->create();
        $theirs = Agency::factory()->create();
        $member = $this->agencyUserWith($mine, ['agency.view', 'agency.update']);

        $offered = $this->actingAs($member)
            ->get(route('admin.agencies.edit', $mine))
            ->assertOk()
            ->viewData('parents');

        $this->assertFalse($offered->contains($theirs->id), 'other agencies must not be listed');
    }

    public function test_platform_staff_still_see_the_whole_network(): void
    {
        $a = Agency::factory()->create();
        $b = Agency::factory()->create();

        $listed = $this->actingAs($this->admin())
            ->get(route('admin.agencies.index'))
            ->assertOk()
            ->viewData('agencies');

        $this->assertTrue($listed->contains($a->id));
        $this->assertTrue($listed->contains($b->id));
    }

    public function test_agency_view_alone_does_not_expose_the_staff_roster(): void
    {
        $agency = Agency::factory()->create();
        $staff = User::factory()->create(['agency_id' => $agency->id, 'name' => 'Roster Person']);
        Role::factory()->create(['agency_id' => $agency->id, 'label' => 'Roster Role']);

        // Holds agency.view only — not user.view, not role.view.
        $viewer = $this->agencyUserWith($agency, ['agency.view']);

        $this->actingAs($viewer)
            ->get(route('admin.agencies.show', $agency))
            ->assertOk()
            ->assertDontSee($staff->name)
            ->assertDontSee('Roster Role')
            ->assertSee('Nothing more to show');
    }

    public function test_the_tab_falls_back_to_one_the_viewer_may_see(): void
    {
        $agency = Agency::factory()->create();
        Role::factory()->create(['agency_id' => $agency->id, 'label' => 'Visible Role']);
        User::factory()->create(['agency_id' => $agency->id, 'name' => 'Hidden Person']);

        // Can see roles but not users; asking for the users tab must not honour it.
        $viewer = $this->agencyUserWith($agency, ['agency.view', 'role.view']);

        $this->actingAs($viewer)
            ->get(route('admin.agencies.show', ['agency' => $agency, 'tab' => 'users']))
            ->assertOk()
            ->assertSee('Visible Role')
            ->assertDontSee('Hidden Person');
    }

    public function test_an_agency_member_gets_no_back_to_agencies_link(): void
    {
        // Their own page is the top of the tree; the agencies list is not theirs.
        $agency = Agency::factory()->create();
        $member = $this->agencyUserWith($agency, ['agency.view', 'agency.update']);

        $this->actingAs($member)
            ->get(route('admin.agencies.show', $agency))
            ->assertOk()
            ->assertDontSee('Back to agencies');

        $this->actingAs($member)
            ->get(route('admin.agencies.edit', $agency))
            ->assertOk()
            ->assertDontSee('Back to agencies')
            ->assertSee('Back to '.$agency->name);
    }

    public function test_platform_staff_keep_the_back_to_agencies_link(): void
    {
        $agency = Agency::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.agencies.show', $agency))
            ->assertOk()
            ->assertSee('Back to agencies');

        $this->actingAs($admin)
            ->get(route('admin.agencies.edit', $agency))
            ->assertOk()
            ->assertSee('Back to agencies');
    }

    public function test_the_create_route_is_not_swallowed_by_the_show_route(): void
    {
        // /agencies/create must keep matching the literal route, not show({agency}).
        $this->actingAs($this->admin())
            ->get(route('admin.agencies.create'))
            ->assertOk()
            ->assertSee('Create Agency');
    }

    public function test_admin_can_create_each_agency_type(): void
    {
        $admin = $this->admin();

        foreach (AgencyType::cases() as $i => $type) {
            $this->actingAs($admin)
                ->post(route('admin.agencies.store'), [
                    'name' => "Branch {$i}",
                    'code' => "branch-{$i}",
                    'type' => $type->value,
                ])
                ->assertRedirect(route('admin.agencies.index'));

            $agency = Agency::where('code', "branch-{$i}")->first();
            $this->assertNotNull($agency);
            $this->assertSame($type, $agency->type);
            $this->assertTrue($agency->is_active);
            $this->assertDatabaseHas('audit_logs', ['event' => 'agency.created', 'auditable_id' => $agency->id]);
        }
    }

    public function test_code_is_generated_from_the_name_when_blank(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.agencies.store'), [
                'name' => 'Cebu Downtown Outlet',
                'code' => '',
                'type' => AgencyType::Outlet->value,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('agencies', ['code' => 'cebu-downtown-outlet']);
    }

    public function test_generated_code_does_not_collide(): void
    {
        Agency::factory()->create(['code' => 'manila-main', 'name' => 'Manila Main']);

        $this->actingAs($this->admin())
            ->post(route('admin.agencies.store'), [
                'name' => 'Manila Main',
                'type' => AgencyType::MainOffice->value,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('agencies', ['code' => 'manila-main-2']);
    }

    public function test_create_rejects_a_duplicate_code(): void
    {
        Agency::factory()->create(['code' => 'taken']);

        $this->actingAs($this->admin())
            ->post(route('admin.agencies.store'), [
                'name' => 'Another',
                'code' => 'taken',
                'type' => AgencyType::Outlet->value,
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_admin_can_rename_an_agency_but_the_code_is_immutable(): void
    {
        $agency = Agency::factory()->create(['code' => 'fixed-code', 'name' => 'Old Name']);

        $this->actingAs($this->admin())
            ->put(route('admin.agencies.update', $agency), [
                'name' => 'New Name',
                'code' => 'attempted-change',
                'type' => AgencyType::Outlet->value,
            ])
            ->assertRedirect(route('admin.agencies.index'));

        $agency->refresh();
        $this->assertSame('New Name', $agency->name);
        $this->assertSame('fixed-code', $agency->code);
    }

    public function test_an_agency_cannot_report_to_itself(): void
    {
        $agency = Agency::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('admin.agencies.update', $agency), [
                'name' => $agency->name,
                'type' => $agency->type->value,
                'parent_id' => $agency->id,
            ])
            ->assertSessionHasErrors('rbac');

        $this->assertNull($agency->refresh()->parent_id);
    }

    public function test_an_agency_cannot_report_to_its_own_descendant(): void
    {
        $office = Agency::factory()->mainOffice()->create();
        $outlet = Agency::factory()->outlet()->create(['parent_id' => $office->id]);

        $this->actingAs($this->admin())
            ->put(route('admin.agencies.update', $office), [
                'name' => $office->name,
                'type' => $office->type->value,
                'parent_id' => $outlet->id,
            ])
            ->assertSessionHasErrors('rbac');

        $this->assertNull($office->refresh()->parent_id);
    }

    public function test_admin_can_toggle_agency_status(): void
    {
        $agency = Agency::factory()->create();

        $this->actingAs($this->admin())
            ->patch(route('admin.agencies.toggle-active', $agency))
            ->assertRedirect();

        $this->assertFalse($agency->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'agency.deactivated', 'auditable_id' => $agency->id]);
    }

    public function test_admin_cannot_toggle_or_delete_their_own_agency(): void
    {
        $agency = Agency::factory()->create();
        $admin = $this->admin();
        $admin->update(['agency_id' => $agency->id]);

        $this->actingAs($admin)->patch(route('admin.agencies.toggle-active', $agency))->assertForbidden();
        $this->actingAs($admin)->delete(route('admin.agencies.destroy', $agency))->assertForbidden();

        $this->assertTrue($agency->refresh()->is_active);
        $this->assertNotSoftDeleted($agency);
    }

    public function test_admin_can_soft_delete_an_empty_agency(): void
    {
        $agency = Agency::factory()->create();

        $this->actingAs($this->admin())
            ->delete(route('admin.agencies.destroy', $agency))
            ->assertRedirect(route('admin.agencies.index'));

        $this->assertSoftDeleted($agency);
        $this->assertDatabaseHas('audit_logs', ['event' => 'agency.deleted', 'auditable_id' => $agency->id]);
    }

    public function test_an_agency_with_members_cannot_be_deleted(): void
    {
        $agency = Agency::factory()->create();
        User::factory()->create(['agency_id' => $agency->id]);

        $this->actingAs($this->admin())
            ->delete(route('admin.agencies.destroy', $agency))
            ->assertSessionHasErrors('rbac');

        $this->assertNotSoftDeleted($agency);
    }

    public function test_an_agency_with_children_cannot_be_deleted(): void
    {
        $office = Agency::factory()->mainOffice()->create();
        Agency::factory()->outlet()->create(['parent_id' => $office->id]);

        $this->actingAs($this->admin())
            ->delete(route('admin.agencies.destroy', $office))
            ->assertSessionHasErrors('rbac');

        $this->assertNotSoftDeleted($office);
    }

    public function test_agency_type_does_not_affect_permissions(): void
    {
        // The same role at a main office and at an ITP resolves to the same abilities:
        // access comes from roles alone, never from the agency's type.
        $office = Agency::factory()->mainOffice()->create();
        $itp = Agency::factory()->itp()->create();

        $atOffice = $this->userWith(['flight.search']);
        $atOffice->update(['agency_id' => $office->id]);

        $atItp = $this->userWith(['flight.search']);
        $atItp->update(['agency_id' => $itp->id]);

        $this->assertTrue($atOffice->hasPermissionTo('flight.search'));
        $this->assertTrue($atItp->hasPermissionTo('flight.search'));
        $this->assertFalse($atOffice->hasPermissionTo('flight.book'));
        $this->assertFalse($atItp->hasPermissionTo('flight.book'));
    }
}
