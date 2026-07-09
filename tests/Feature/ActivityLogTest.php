<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Rbac\AuditLogger;
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

    public function test_login_and_logout_are_audited(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event' => 'auth.login']);

        $this->post('/logout')->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event' => 'auth.logout']);
    }

    public function test_page_visits_are_not_logged(): void
    {
        $user = $this->userWith(['flight.view']);

        $before = AuditLog::count();
        $this->actingAs($user)->get(route('flights'))->assertOk();

        // Only meaningful actions are recorded — navigation is not.
        $this->assertSame($before, AuditLog::count());
    }

    public function test_activity_tab_shows_the_users_actions(): void
    {
        $actor = User::factory()->create(['name' => 'Jane Admin']);
        $target = User::factory()->create();

        // An action performed BY Jane (actor = current user).
        $this->actingAs($actor);
        app(AuditLogger::class)->log('user.updated', $target, [], 'Updated a user');

        $this->actingAs($this->userWith(['user.view', 'apilog.view']))
            ->get(route('admin.users.logs', ['user' => $actor, 'tab' => 'activity']))
            ->assertOk()
            ->assertSee('Activity')
            ->assertSee('Updated a user');
    }
}
