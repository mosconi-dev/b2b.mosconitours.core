<?php

namespace App\Services\Pricing\Calculators;

use App\Models\PricingRule;
use App\Services\Pricing\Money;
use App\Services\Pricing\PricingContext;

/**
 * A flat amount for every passenger — the air trade's transaction fee per ticket issued.
 *
 * The industry norm on flights, and the reason `paxCount` has been on the context since
 * it was written. A family of five is where the difference from a per-booking fee stops
 * being theoretical.
 *
 * **Flights only.** A hotel rate is per room per night, not per head: two adults in one
 * double room pay one room rate, and charging them two of these scales the fee on the
 * wrong axis. That was the live system's bug, and CalcType::forProduct() is what keeps
 * this type off the hotel form — see PerRoomNightCalculator for the right shape there.
 */
final class PerPaxCalculator implements Calculator
{
    public function compute(Money $basis, PricingRule $rule, PricingContext $context): Money
    {
        return Money::of($rule->value)->times($context->paxCount);
    }
}
