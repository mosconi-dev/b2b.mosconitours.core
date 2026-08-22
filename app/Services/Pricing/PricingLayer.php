<?php

namespace App\Services\Pricing;

use App\Enums\CalcType;
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

    /**
     * What this rung added, as a phrase the browser can print without knowing what a
     * calculation type is.
     *
     * Derived from the snapshot rather than the rule, like everything else here — the
     * rule may already have changed, and a rung must still describe what it actually
     * charged.
     */
    public function amountLabel(): string
    {
        $type = CalcType::tryFrom((string) ($this->ruleSnapshot['calc_type'] ?? ''));

        // A tiered rung names the band it actually landed in, which is the only thing
        // that explains its number — the table alone would not.
        if ($type === CalcType::Tiered) {
            return TieredBands::labelFor($this->ruleSnapshot['params'] ?? null, $this->basis);
        }

        return $type?->describeAmount($this->ruleSnapshot['value'] ?? null) ?? '';
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
     * toViewerArray() instead — AgencyPriceView does.
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
            'label' => $this->amountLabel(),
            'description' => $this->ruleSnapshot['description'] ?? null,
            'basisAmount' => (string) $this->basis,
            'markup' => (string) $this->markup,
            'runningTotal' => (string) $this->runningTotal,
        ];
    }

    /**
     * The rung as its own owner may see it: what they added, and which of THEIR rules
     * did it.
     *
     * The matching criteria are here so the preview can answer "why this rule and not
     * the one below it" — which is the question an agency actually has once it holds
     * more than one rule. They are the agency's own configuration, so naming them back
     * discloses nothing.
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
            // Derived from the two lines above and discloses nothing they do not.
            'label' => $this->amountLabel(),
            'markup' => (string) $this->markup,
            // Which of their own rules fired, why it exists, and what it matched on.
            'description' => $this->ruleSnapshot['description'] ?? null,
            'product' => $this->ruleSnapshot['product'] ?? null,
            'scope' => $this->ruleSnapshot['scope'] ?? null,
            'supplier' => $this->ruleSnapshot['supplier'] ?? null,
            'priority' => $this->ruleSnapshot['priority'] ?? null,
            'basis' => $this->ruleSnapshot['basis'] ?? null,
            'minMarkup' => $this->ruleSnapshot['min_markup'] ?? null,
            'maxMarkup' => $this->ruleSnapshot['max_markup'] ?? null,
        ];
    }
}
