<?php

namespace App\Services\Pricing\Calculators;

use App\Models\PricingRule;
use App\Services\Pricing\Money;
use App\Services\Pricing\PricingContext;

/**
 * Turns a basis amount into a markup, one implementation per CalcType.
 *
 * This interface is the extension point: the engine resolves a Calculator from the
 * registry and calls it, so a new pricing strategy is a new class and a registry line —
 * never a change to the engine, the resolver, the matcher or the layers table.
 *
 * Implementations must be pure: same basis, same rule, same context, same answer. The
 * ladder preview depends on it, and so does anyone reconciling a booking's stored layers
 * against what the engine would produce today.
 */
interface Calculator
{
    /**
     * The markup this rule adds. Never negative — see PricingEngine, which treats a
     * negative contribution as a configuration error rather than a discount.
     */
    public function compute(Money $basis, PricingRule $rule, PricingContext $context): Money;
}
