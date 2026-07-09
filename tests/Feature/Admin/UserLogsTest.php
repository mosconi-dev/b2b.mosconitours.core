<?php

namespace Tests\Feature\Admin;

use App\Models\TboAirApiLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class UserLogsTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    private function logFor(User $user, array $attrs = []): TboAirApiLog
    {
        return TboAirApiLog::create(array_merge([
            'type' => 'search',
            'environment' => 'test',
            'endpoint' => 'https://api-stage.tboair.com/search',
            'status_code' => 200,
            'successful' => true,
            'duration_ms' => 120,
            'user_id' => $user->id,
            'request' => ['Segments' => [['Origin' => 'MNL', 'Destination' => 'CEB']]],
            'response' => ['ok' => true],
        ], $attrs));
    }

    public function test_page_requires_apilog_view_permission(): void
    {
        $target = User::factory()->create();

        // Has user.view but not apilog.view → forbidden.
        $this->actingAs($this->userWith(['user.view']))
            ->get(route('admin.users.logs', $target))
            ->assertForbidden();
    }

    public function test_shows_only_the_target_users_logs(): void
    {
        $jane = User::factory()->create(['name' => 'Jane Traveler']);
        $bob = User::factory()->create(['name' => 'Bob Other']);

        $this->logFor($jane); // MNL → CEB
        $this->logFor($bob, ['request' => ['Segments' => [['Origin' => 'XXX', 'Destination' => 'YYY']]]]);

        $this->actingAs($this->userWith(['user.view', 'apilog.view']))
            ->get(route('admin.users.logs', $jane))
            ->assertOk()
            ->assertSee('Jane Traveler')
            ->assertSee('MNL → CEB')
            ->assertDontSee('XXX → YYY');
    }

    public function test_type_filter_limits_results(): void
    {
        $jane = User::factory()->create();
        $this->logFor($jane); // search: MNL → CEB
        $this->logFor($jane, ['type' => 'authenticate', 'endpoint' => 'auth', 'request' => []]);

        $this->actingAs($this->userWith(['user.view', 'apilog.view']))
            ->get(route('admin.users.logs', ['user' => $jane, 'type' => 'authenticate']))
            ->assertOk()
            ->assertDontSee('MNL → CEB'); // the search row is filtered out
    }

    public function test_users_index_shows_a_logs_link_when_permitted(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->userWith(['user.view', 'apilog.view']))
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee(route('admin.users.logs', $target));
    }

    public function test_users_index_hides_the_logs_link_without_apilog_view(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->userWith(['user.view']))
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertDontSee(route('admin.users.logs', $target));
    }
}
