<?php

namespace App\Services\Pricing\Calculators;

use App\Models\PricingRule;
use App\Services\Pricing\Money;
use App\Services\Pricing\PricingContext;

/**
 * Nothing, said out loud.
 *
 * A negotiated corporate rate, a staff booking, a supplier we pass through at cost. The
 * alternative to this type is no rule at all, and the two look identical in the admin
 * list while meaning very different things: one is a decision, the other is an omission
 * nobody has noticed yet.
 *
 * It contributes a zero rung to the ladder rather than no rung, so the breakdown on a
 * booking says which rule decided to take nothing.
 */
final class NoneCalculator implements Calculator
{
    public function compute(Money $basis, PricingRule $rule, PricingContext $context): Money
    {
        return Money::zero();
    }
}
