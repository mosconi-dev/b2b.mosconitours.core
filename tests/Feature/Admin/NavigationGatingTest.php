<?php

namespace Tests\Feature\Admin;

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
