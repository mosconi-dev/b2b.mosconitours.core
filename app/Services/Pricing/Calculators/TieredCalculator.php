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
 * sees it, after `applies_to` has taken its slice — divided by however many units the
 * table says it measures. A flight table defaults to the per-ticket reading, so three
 * seats at ₱10,000 are banded at ₱10,000 rather than at the ₱30,000 they come to. See
 * TierUnit.
 *
 * That is not in tension with per-passenger types being refused INSIDE a band. A unit
 * count multiplies the whole table uniformly, so it cannot change which side of a
 * boundary anything falls on; a per-passenger band would change one band's arithmetic
 * relative to its neighbours, which is exactly what the cliff check has to be able to
 * see when somebody writes the table.
 *
 * Whether one band prices the whole fare or every band prices its own slice is the
 * table's own choice too; see TierMode, and the cliff it exists to get around.
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
        $table = TieredBands::fromParams($rule->params);

        return $table->markupOn($basis, $table->unit()->unitsIn($context));
    }
}
