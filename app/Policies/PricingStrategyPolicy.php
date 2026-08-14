<?php

namespace App\Policies;

use App\Models\PricingStrategy;
use App\Models\User;
use App\Services\Pricing\StrategyResolver;

/**
 * Who may read and edit an agency's own markup.
 *
 * Permissions decide *what* a user may do; this decides *whose* pricing they may do it
 * to. Holding `markup.edit` in one agency must do nothing in another, or a partner
 * could reprice a competitor.
 *
 * The Main Office's own strategy is deliberately NOT editable through here. It is the
 * level every other agency's price is built on, so it lives behind `markup.office.*`
 * and its own controller — an agency holding `markup.edit` must not reach it just
 * because it happens to be a strategy like any other.
 */
class PricingStrategyPolicy
{
    public function __construct(private readonly StrategyResolver $resolver) {}

    public function view(User $user, PricingStrategy $strategy): bool
    {
        return $user->can('markup.view') && $this->ownsIt($user, $strategy);
    }

    public function update(User $user, PricingStrategy $strategy): bool
    {
        return $user->can('markup.edit')
            && $this->ownsIt($user, $strategy)
            && ! $this->isPricingRoot($strategy);
    }

    /**
     * Platform staff may act on any agency's strategy — the same row-scope exemption
     * they have everywhere else, and not a permission bypass: they still need the
     * ability, because there is no Gate::before.
     */
    private function ownsIt(User $user, PricingStrategy $strategy): bool
    {
        return $user->isPlatformStaff() || $user->agency_id === $strategy->agency_id;
    }

    /**
     * The pricing root is off limits here even to platform staff, so there is exactly
     * one route to changing it and one permission guarding that route.
     */
    private function isPricingRoot(PricingStrategy $strategy): bool
    {
        return $this->resolver->isConfigured()
            && $this->resolver->mainOffice()->getKey() === $strategy->agency_id;
    }
}
