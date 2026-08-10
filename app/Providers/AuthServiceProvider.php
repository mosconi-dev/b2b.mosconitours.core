<?php

namespace App\Providers;

use App\Models\Agency;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLoadRequest;
use App\Models\WalletTransaction;
use App\Policies\AgencyPolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Policies\WalletLoadRequestPolicy;
use App\Policies\WalletPolicy;
use App\Policies\WalletTransactionPolicy;
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
        Gate::policy(Agency::class, AgencyPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Wallet::class, WalletPolicy::class);
        Gate::policy(WalletLoadRequest::class, WalletLoadRequestPolicy::class);
        Gate::policy(WalletTransaction::class, WalletTransactionPolicy::class);

        foreach ($registry->permissionNames() as $name) {
            Gate::define($name, fn (User $user): bool => $user->hasPermissionTo($name));
        }
    }
}
