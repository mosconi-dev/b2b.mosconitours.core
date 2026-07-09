<?php

namespace Tests\Feature\TboAir;

use App\Models\User;
use App\Services\Settings\Settings;
use App\Services\TboAir\TboEnvironmentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class PerUserEnvironmentTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    private function resolve(User $user): string
    {
        return app(TboEnvironmentResolver::class)->resolve($user);
    }

    public function test_live_override_requires_the_use_live_permission(): void
    {
        $blocked = $this->userWith(['flight.view']);
        $blocked->update(['tbo_environment' => 'live']);
        $this->assertSame('test', $this->resolve($blocked->fresh()));

        $allowed = $this->userWith(['supplier.tbo.live']);
        $allowed->update(['tbo_environment' => 'live']);
        $this->assertSame('live', $this->resolve($allowed->fresh()));
    }

    public function test_test_override_wins_over_a_global_live_default(): void
    {
        app(Settings::class)->set(TboEnvironmentResolver::SETTING_KEY, 'live');

        $user = $this->userWith(['flight.view']);
        $user->update(['tbo_environment' => 'test']);

        $this->assertSame('test', $this->resolve($user->fresh()));
    }

    public function test_no_override_follows_the_global_default(): void
    {
        app(Settings::class)->set(TboEnvironmentResolver::SETTING_KEY, 'live');

        // Global live is a platform decision — not per-user gated.
        $user = $this->userWith(['flight.view']);

        $this->assertSame('live', $this->resolve($user->fresh()));
    }

    public function test_manager_can_set_a_users_environment_override(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'tbo_environment' => 'live',
            ])->assertRedirect();

        $this->assertSame('live', $target->fresh()->tbo_environment);
    }

    public function test_non_manager_cannot_set_an_environment_override(): void
    {
        $target = User::factory()->create();
        $operator = $this->userWith(['user.view', 'user.update']); // can edit users, not manage TBO

        $this->actingAs($operator)
            ->put(route('admin.users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'tbo_environment' => 'live',
            ])->assertRedirect();

        $this->assertNull($target->fresh()->tbo_environment);
    }
}
