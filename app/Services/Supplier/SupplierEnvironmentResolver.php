<?php

namespace App\Services\Supplier;

use App\Enums\Supplier;
use App\Models\User;
use App\Services\Settings\Settings;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * Decides which environment ("test"/"live") applies to a supplier.
 *
 * Precedence: per-user override → global setting → config default.
 *
 * The per-user override is a **single switch across all suppliers** — an agent who
 * is testing is testing everything, and a session split half across live would be a
 * trap rather than a feature. What is per-supplier is the *permission* to use live
 * at all, and the platform-wide default. Both are on the Supplier enum.
 *
 * An override to "live" without that supplier's live permission falls back to test
 * rather than failing, so a stale user preference can never route real money.
 */
class SupplierEnvironmentResolver
{
    public function __construct(private readonly Settings $settings) {}

    public function resolve(Supplier $supplier, ?Authenticatable $user = null): string
    {
        $user ??= Auth::user();

        if ($user instanceof User && $user->tbo_environment) {
            $env = $this->normalize($user->tbo_environment);

            if ($env === 'live' && ! $user->can($supplier->livePermission())) {
                return 'test'; // override to live not permitted — safe fallback
            }

            return $env;
        }

        return $this->normalize($this->globalFor($supplier));
    }

    /**
     * Which suppliers this user is currently pointed at live.
     *
     * Drives the LIVE badge in the header. It names the suppliers rather than just
     * warning, because "live" stopped being a single fact the moment there were two
     * of them — flights on live and hotels on test is a normal, and dangerous, state
     * to be in without being told.
     *
     * @return array<int, Supplier>
     */
    public function liveSuppliers(?Authenticatable $user = null): array
    {
        return array_values(array_filter(
            Supplier::cases(),
            fn (Supplier $supplier): bool => $this->resolve($supplier, $user) === 'live',
        ));
    }

    /**
     * The platform-wide choice for a supplier, ignoring any per-user override.
     */
    public function globalFor(Supplier $supplier): string
    {
        $configured = config($supplier->configKey().'.default', 'test');

        return $this->normalize($this->settings->get($supplier->settingKey()) ?: $configured);
    }

    public function normalize(string $env): string
    {
        return in_array($env, ['test', 'live'], true) ? $env : 'test';
    }
}
