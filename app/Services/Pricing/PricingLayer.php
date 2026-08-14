<?php

namespace App\Services\Pricing;

use App\Models\Agency;
use App\Models\PricingRule;

/**
 * One rung of the ladder, as computed.
 *
 * Carries the rule's snapshot rather than the rule, so what is written to
 * `booking_price_layers` is decided here — at the moment the price is made — rather
 * than later, when the rule may already have changed.
 */
final readonly class PricingLayer
{
    /**
     * @param  array<string, mixed>  $ruleSnapshot
     */
    public function __construct(
        public int $level,
        public Agency $agency,
        public ?int $strategyId,
        public ?int $ruleId,
        public array $ruleSnapshot,
        public Money $basis,
        public Money $markup,
        public Money $runningTotal,
    ) {}

    public static function from(
        int $level,
        Agency $agency,
        PricingRule $rule,
        Money $basis,
        Money $markup,
        Money $runningTotal,
    ): self {
        return new self(
            level: $level,
            agency: $agency,
            strategyId: (int) $rule->pricing_strategy_id,
            ruleId: (int) $rule->getKey(),
            ruleSnapshot: $rule->snapshot(),
            basis: $basis,
            markup: $markup,
            runningTotal: $runningTotal,
        );
    }

    /** The row shape for `booking_price_layers`. */
    public function toRow(): array
    {
        return [
            'level' => $this->level,
            'agency_id' => $this->agency->getKey(),
            'pricing_strategy_id' => $this->strategyId,
            'pricing_rule_id' => $this->ruleId,
            'rule_snapshot' => $this->ruleSnapshot,
            'basis_amount' => (string) $this->basis,
            'markup_amount' => (string) $this->markup,
            'running_total' => (string) $this->runningTotal,
            'created_at' => now(),
        ];
    }

    /**
     * The full rung. For the ladder preview and the admin screens only.
     *
     * **`basisAmount` is the supplier net whenever the rule uses a `net` basis**, so
     * this must never be serialized to a viewer below this level. Send
     * toViewerArray() instead — PriceBreakdown::forViewer() does.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'agencyId' => $this->agency->getKey(),
            'agencyName' => $this->agency->name,
            'ruleId' => $this->ruleId,
            'calcType' => $this->ruleSnapshot['calc_type'] ?? null,
            'value' => $this->ruleSnapshot['value'] ?? null,
            'basisAmount' => (string) $this->basis,
            'markup' => (string) $this->markup,
            'runningTotal' => (string) $this->runningTotal,
        ];
    }

    /**
     * The rung as its own owner may see it: what they added, and what rule did it.
     *
     * `basisAmount` and `runningTotal` are dropped. The basis is the supplier net on a
     * `net`-basis rule, which is precisely the figure an agency must not receive, and
     * handing it over inside "their own" layer is the easiest way to leak it without
     * noticing.
     *
     * @return array<string, mixed>
     */
    public function toViewerArray(): array
    {
        return [
            'level' => $this->level,
            'agencyName' => $this->agency->name,
            'calcType' => $this->ruleSnapshot['calc_type'] ?? null,
            'value' => $this->ruleSnapshot['value'] ?? null,
            'markup' => (string) $this->markup,
        ];
    }
}
