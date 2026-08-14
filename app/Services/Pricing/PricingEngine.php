<?php

namespace App\Services\Pricing;

use App\Models\Agency;
use App\Models\PricingRule;
use App\Services\Pricing\Calculators\CalculatorRegistry;
use App\Services\Pricing\Exceptions\PricingException;

/**
 * Turns a supplier's net rate into a selling price.
 *
 * The whole model in one loop: walk the levels the resolver returns, take at most one
 * matching rule from each, and SUM their contributions. Pricing is cumulative — an
 * agency's markup adds to the Main Office's, it does not replace it — and because no
 * level ever reads another level's rules, "additional, never an override" is a property
 * of the shape rather than a condition anyone has to check.
 *
 * Pure: same context and same configuration, same answer. It reads no booking, no
 * wallet and no session, which is what lets the admin ladder preview be exact rather
 * than an estimate — it runs this same code.
 *
 * ACCEPTS A NetPrice AND NOTHING ELSE. See NetPrice for why that matters.
 */
class PricingEngine
{
    public function __construct(
        private readonly StrategyResolver $resolver,
        private readonly RuleMatcher $matcher,
        private readonly CalculatorRegistry $calculators,
    ) {}

    public function quote(PricingContext $context, ?Agency $booker): PriceBreakdown
    {
        $net = $context->net->amount;
        $running = $net;
        $layers = [];

        foreach ($this->resolver->chain($booker) as $rung) {
            $strategy = $rung['strategy'];

            // No strategy, or a paused one: this level contributes nothing. That is a
            // legitimate answer meaning "we take no margin here", not a failure — and it
            // is what an empty configuration produces, which is how this engine ships
            // live without moving a single price.
            if ($strategy === null) {
                continue;
            }

            $rule = $this->matcher->firstMatch($strategy, $context);

            if ($rule === null) {
                continue;
            }

            $basis = $this->basisFor($rule, $context, $net, $running);
            $markup = $this->markupFor($rule, $basis, $context);

            $running = $running->plus($markup);

            $layers[] = PricingLayer::from(
                level: $rung['level'],
                agency: $rung['agency'],
                rule: $rule,
                basis: $basis,
                markup: $markup,
                runningTotal: $running,
            );
        }

        $running = $this->capTotal($net, $running);

        // Rounded ONCE, at the end. Rounding each rung and summing produces a total
        // that does not equal the rounded sum, and a breakdown that visibly fails to
        // add up is worse than the drift it was meant to tidy.
        $sell = $running->roundUpTo((int) config('pricing.rounding', 0));

        return new PriceBreakdown(
            net: $context->net,
            layers: $layers,
            sell: new SellPrice($sell),
            roundingDelta: $sell->minus($running),
            currency: $context->currency,
        );
    }

    /**
     * What this rule's percentage is a percentage of.
     *
     * `net` does not compound and is the house default; `running` compounds against the
     * levels above. A fixed rule ignores the distinction, but it is resolved uniformly
     * so the recorded `basis_amount` always says what the rule was applied to.
     */
    private function basisFor(PricingRule $rule, PricingContext $context, Money $net, Money $running): Money
    {
        $base = $rule->basis->value === 'running'
            ? $running
            : $net;

        // `applies_to` narrows within the chosen amount — base fare only, or excluding
        // ancillaries. On a running basis those parts are not separable, so the whole
        // running total stands.
        if ($rule->basis->value === 'running' || $rule->applies_to === 'total') {
            return $base;
        }

        return $context->basisFor((string) $rule->applies_to);
    }

    private function markupFor(PricingRule $rule, Money $basis, PricingContext $context): Money
    {
        $markup = $this->calculators->for($rule->calc_type)->compute($basis, $rule, $context);

        $markup = $markup->clamp(
            $rule->min_markup === null ? null : Money::of($rule->min_markup),
            $rule->max_markup === null ? null : Money::of($rule->max_markup),
        );

        // A negative contribution is a discount, which has different authorization,
        // audit and accounting from a markup and is deliberately not this mechanism.
        // Refused rather than clamped to zero, so a rule entered wrongly is found.
        if ($markup->isNegative()) {
            throw new PricingException(
                "Pricing rule #{$rule->getKey()} produced a negative markup ({$markup}). "
                .'Discounts are not supported; correct the rule.'
            );
        }

        return $markup->roundUpTo($rule->rounding === 'none' ? 0 : (int) $rule->rounding);
    }

    /**
     * A ceiling on everything the levels added together.
     *
     * Two individually reasonable levels can stack into something the end customer
     * reads as absurd, and they see only the final number. Unset by default — the cap
     * is a business decision, and until it is taken this is a no-op.
     */
    private function capTotal(Money $net, Money $running): Money
    {
        $cap = config('pricing.max_total_markup');

        if (blank($cap)) {
            return $running;
        }

        $ceiling = $net->plus(Money::of($cap));

        return $running->greaterThan($ceiling) ? $ceiling : $running;
    }
}
