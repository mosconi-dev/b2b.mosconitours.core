<?php

namespace App\Services\Pricing;

use App\Models\PricingRule;
use App\Models\PricingStrategy;

/**
 * The one rule a strategy contributes, or none.
 *
 * FIRST MATCH WINS, by priority ascending. Not a sum of every matching rule: a price
 * built from four overlapping rules cannot be explained to an agency without replaying
 * the whole table, and "why is this fare up 40%?" is a question that gets asked.
 *
 * Narrow rules therefore sit above broad ones, and a catch-all sits at the bottom.
 */
class RuleMatcher
{
    public function firstMatch(PricingStrategy $strategy, PricingContext $context): ?PricingRule
    {
        // activeRules is already ordered by priority then id, and eager-loaded by the
        // resolver — so this walks a small in-memory collection rather than querying.
        foreach ($strategy->activeRules as $rule) {
            if ($rule->matches($context)) {
                return $rule;
            }
        }

        return null;
    }
}
