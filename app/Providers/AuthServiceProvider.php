<?php

namespace App\Providers;

use App\Models\Role;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Services\Rbac\PermissionRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register model policies and one Gate per registry permission.
     *
     * The gate loop reads permission names from the registry (config, not the
     * database), so it is safe during migrations/console. There is deliberately
     * NO Gate::before — no role, not even Admin, bypasses a permission check.
     */
    public function boot(PermissionRegistry $registry): void
    {
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        foreach ($registry->permissionNames() as $name) {
            Gate::define($name, fn (User $user): bool => $user->hasPermissionTo($name));
        }
    }
}
