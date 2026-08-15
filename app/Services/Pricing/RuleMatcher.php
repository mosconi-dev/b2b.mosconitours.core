<?php

namespace App\Services\Pricing;

use App\Models\PricingRule;
use App\Models\PricingStrategy;

/**
 * Every rule a strategy contributes to one priced item.
 *
 * CUMULATIVE: all matching rules apply and their contributions sum. A base percentage,
 * a service fee and an international surcharge are three rules, and an international
 * booking pays all three — which is how the business actually prices.
 *
 * Returned in priority order, which is the order they are applied in. With every rule
 * on a `net` basis the order does not change the total, because addition commutes; it
 * matters only for a `running`-basis rule, which compounds against what came before it,
 * and for the order the rungs are shown in.
 *
 * The cost of this model is that a forgotten rule keeps charging rather than falling
 * silently dead, so the preview names every rule that fired.
 */
class RuleMatcher
{
    /**
     * @return array<int, PricingRule>
     */
    public function allMatches(PricingStrategy $strategy, PricingContext $context): array
    {
        $matched = [];

        // activeRules is already ordered by priority then id, and eager-loaded by the
        // resolver — so this walks a small in-memory collection rather than querying.
        foreach ($strategy->activeRules as $rule) {
            if ($rule->matches($context)) {
                $matched[] = $rule;
            }
        }

        return $matched;
    }
}
