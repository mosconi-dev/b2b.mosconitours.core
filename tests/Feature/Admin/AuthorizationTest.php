<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function adminRoutes(): array
    {
        return [
            ['admin.dashboard', 'admin.access'],
            ['admin.users.index', 'user.view'],
            ['admin.users.create', 'user.create'],
            ['admin.roles.index', 'role.view'],
            ['admin.permissions.index', 'permission.view'],
            ['admin.audit-logs.index', 'audit.view'],
            ['admin.settings.index', 'setting.view'],
        ];
    }

    #[DataProvider('adminRoutes')]
    public function test_route_denies_users_lacking_its_permission(string $route, string $permission): void
    {
        $this->actingAs($this->userWith(['flight.view']))
            ->get(route($route))
            ->assertForbidden();
    }

    #[DataProvider('adminRoutes')]
    public function test_route_allows_users_holding_its_permission(string $route, string $permission): void
    {
        $this->actingAs($this->userWith([$permission]))
            ->get(route($route))
            ->assertOk();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
    }

    public function test_database_seeder_provisions_a_working_admin(): void
    {
        $this->seed();

        $this->assertDatabaseHas('roles', ['name' => 'admin', 'is_system' => true]);
        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);

        $user = User::where('email', 'test@example.com')->first();
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->hasPermissionTo('user.create'));
        $this->assertTrue($user->hasPermissionTo('role.update'));
    }
}
