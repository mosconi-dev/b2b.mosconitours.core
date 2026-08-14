<?php

namespace App\Services\Pricing\Calculators;

use App\Models\PricingRule;
use App\Services\Pricing\Money;
use App\Services\Pricing\PricingContext;

/**
 * A percentage OF COST — 10% on ₱5,000 adds ₱500 and sells at ₱5,500.
 *
 * Not to be confused with percentage *margin*, which is a percentage of the selling
 * price: 20% margin on ₱5,000 adds ₱1,250 and sells at ₱6,250, against markup's ₱1,000
 * and ₱6,000. Anyone who says "we work on 20%" means one of the two, and the difference
 * is ₱250 a booking — which is why they are separate types rather than one type with a
 * flag someone has to remember the meaning of.
 *
 * Which amount the percentage is taken of — the supplier net or the running total — is
 * the rule's `basis`, applied by the engine before this is called.
 */
final class PercentageMarkupCalculator implements Calculator
{
    public function compute(Money $basis, PricingRule $rule, PricingContext $context): Money
    {
        return $basis->percent($rule->value);
    }
}
