<?php

namespace App\Services\Pricing\Calculators;

use App\Models\PricingRule;
use App\Services\Pricing\Money;
use App\Services\Pricing\PricingContext;

/**
 * A flat amount for every room, every night — the axis a hotel rate actually moves on.
 *
 * Two rooms for three nights is six of these. Head count is deliberately not a factor:
 * the live system multiplied hotel markup by it, so two adults in one double room paid
 * one room rate and two markups, and the fee grew with a number the supplier never
 * charged on.
 */
final class PerRoomNightCalculator implements Calculator
{
    public function compute(Money $basis, PricingRule $rule, PricingContext $context): Money
    {
        return Money::of($rule->value)->times($context->roomCount * $context->nights);
    }
}
