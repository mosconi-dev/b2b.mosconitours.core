<?php

namespace App\Services\Pricing\Calculators;

use App\Models\PricingRule;
use App\Services\Pricing\Money;
use App\Services\Pricing\PricingContext;

/**
 * A flat amount, whatever the fare.
 *
 * The right shape for thin-margin inventory: a percentage that is sensible on a
 * full-service international ticket prices a domestic budget seat out of the market,
 * and with levels stacking that error multiplies.
 */
final class FixedCalculator implements Calculator
{
    public function compute(Money $basis, PricingRule $rule, PricingContext $context): Money
    {
        return Money::of($rule->value);
    }
}
