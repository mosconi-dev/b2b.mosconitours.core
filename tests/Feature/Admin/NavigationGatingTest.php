<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class NavigationGatingTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_admin_sees_the_administration_section(): void
    {
        $this->actingAs($this->admin())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Administration')
            ->assertSee('Users')
            ->assertSee('Roles');
    }

    public function test_user_without_admin_permissions_sees_no_admin_nav(): void
    {
        $this->actingAs($this->userWith(['flight.view']))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Administration')
            ->assertSee('Flights');
    }

    public function test_an_agency_member_gets_a_my_agency_link_to_their_own_agency(): void
    {
        $agency = Agency::factory()->create();
        $member = $this->agencyUserWith($agency, ['agency.view']);

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('My Agency')
            // Matched on the full href: the index URL is a prefix of the show URL,
            // so a bare substring check would always find it.
            ->assertSee('href="'.route('admin.agencies.show', $agency).'"', escape: false)
            // A list of exactly one row is not where they should land.
            ->assertDontSee('href="'.route('admin.agencies.index').'"', escape: false);
    }

    public function test_platform_staff_get_the_full_agencies_list(): void
    {
        $this->actingAs($this->admin())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Agencies')
            ->assertSee('href="'.route('admin.agencies.index').'"', escape: false);
    }

    public function test_an_agency_member_gets_no_users_or_roles_link_because_my_agency_has_the_tabs(): void
    {
        $agency = Agency::factory()->create();
        $member = $this->agencyUserWith($agency, ['agency.view', 'user.view', 'role.view']);

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('My Agency')
            ->assertDontSee('href="'.route('admin.users.index').'"', escape: false)
            ->assertDontSee('href="'.route('admin.roles.index').'"', escape: false);
    }

    public function test_hiding_those_links_does_not_lock_the_pages(): void
    {
        $agency = Agency::factory()->create();
        $member = $this->agencyUserWith($agency, ['user.view', 'role.view']);

        $this->actingAs($member)->get('/admin/users')->assertOk();
        $this->actingAs($member)->get('/admin/roles')->assertOk();
    }

    public function test_nav_items_appear_per_permission(): void
    {
        $this->actingAs($this->userWith(['user.view']))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Administration')
            ->assertSee('Users')
            ->assertDontSee('Roles');
    }
}
