<?php

namespace App\Services\Pricing\Calculators;

use App\Models\PricingRule;
use App\Services\Pricing\Money;
use App\Services\Pricing\PricingContext;
use App\Services\Pricing\TieredBands;

/**
 * Charge one way up to an amount, another way above it.
 *
 * Bands are read against the basis the engine hands over — the whole booking as this rule
 * sees it, after `applies_to` has taken its slice. **Not per passenger**: three seats at
 * ₱10,000 each is a ₱30,000 fare and is banded as ₱30,000. That is the reading the form
 * states out loud, and it is why per-passenger types are not allowed inside a band —
 * charge those with a second rule, which adds on top.
 *
 * Whether one band prices the whole fare or every band prices its own slice is the
 * table's own choice; see TierMode, and the cliff it exists to get around.
 *
 * The rule's own `value` is unused. A tiered rule's numbers all live in its bands, which
 * is what the `params` column exists for.
 *
 * See TieredBands for the cliff at every boundary, and for the check that refuses a
 * table where the more expensive fare sells for less.
 */
final class TieredCalculator implements Calculator
{
    public function compute(Money $basis, PricingRule $rule, PricingContext $context): Money
    {
        return TieredBands::fromParams($rule->params)->markupOn($basis);
    }
}
