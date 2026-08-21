<?php

namespace App\Services\Pricing\Calculators;

use App\Models\PricingRule;
use App\Services\Pricing\Money;
use App\Services\Pricing\PricingContext;

/**
 * A percentage OF THE SELLING PRICE — 20% on ₱5,000 adds ₱1,250 and sells at ₱6,250.
 *
 * The counterpart to PercentageMarkupCalculator, which takes its percentage of cost and
 * on the same fare adds ₱1,000. Anyone who says "we work on 20%" means one of the two.
 * They are separate types rather than one type with a flag because a flag is a thing
 * somebody has to remember the meaning of, and ₱250 a booking is too much to leave to
 * memory.
 *
 * Which amount the percentage is taken of — the supplier net or the running total — is
 * still the rule's `basis`, applied by the engine before this is called.
 */
final class PercentageMarginCalculator implements Calculator
{
    public function compute(Money $basis, PricingRule $rule, PricingContext $context): Money
    {
        return $basis->margin($rule->value);
    }
}
