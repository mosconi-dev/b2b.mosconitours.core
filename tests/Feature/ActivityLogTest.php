<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    public function test_authenticated_page_visits_are_logged(): void
    {
        $user = $this->userWith(['flight.view']);

        $this->actingAs($user)->get(route('flights'))->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'page.viewed',
            'route' => 'flights',
            'description' => 'Flights',
        ]);
    }

    public function test_a_page_visit_creates_a_single_entry(): void
    {
        $user = $this->userWith(['flight.view']);

        $this->actingAs($user)->get(route('flights'))->assertOk();

        $this->assertDatabaseCount('activity_logs', 1);
    }

    public function test_guests_are_not_logged(): void
    {
        $this->get(route('login'))->assertOk();

        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_login_and_logout_are_logged(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'action' => 'auth.login']);

        $this->post('/logout')->assertRedirect();
        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'action' => 'auth.logout']);
    }

    public function test_per_user_activity_tab_shows_entries(): void
    {
        $jane = User::factory()->create(['name' => 'Jane Traveler']);
        Activity::create([
            'user_id' => $jane->id,
            'action' => 'flight.searched',
            'description' => 'Searched MNL → CEB',
            'created_at' => now(),
        ]);

        $this->actingAs($this->userWith(['user.view', 'apilog.view']))
            ->get(route('admin.users.logs', ['user' => $jane, 'tab' => 'activity']))
            ->assertOk()
            ->assertSee('Activity')
            ->assertSee('Searched MNL → CEB');
    }
}
