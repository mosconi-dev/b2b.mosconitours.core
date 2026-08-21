<?php

namespace App\Services\Pricing;

/**
 * What the engine returns: a net price, the rungs added to it, and the selling price.
 *
 * This is the INTERNAL model — the complete ladder, including the supplier's net and
 * every level's rule and contribution. It exists to be computed against, written to
 * `booking_price_layers` as the audit record, and read back by margin reporting.
 *
 * **It must never be serialized to an agency.** Search results travel to the browser as
 * JSON, so filtering in a Blade template is one devtools panel away from useless. The
 * external representation is a separate type — see AgencyPriceView, which is built from
 * this one for a named viewer and carries only what that viewer may see. Reaching for
 * `toArray()` on an agency-facing path is the mistake this split exists to make visible.
 */
final readonly class PriceBreakdown
{
    /**
     * @param  array<int, PricingLayer>  $layers  root first
     */
    public function __construct(
        public NetPrice $net,
        public array $layers,
        public SellPrice $sell,
        /** sell − the unrounded running total, when a rounding step moved it. */
        public Money $roundingDelta,
        public string $currency = 'PHP',
        /**
         * Where the booker sits on the ladder, taken from the CHAIN and not from the
         * layers.
         *
         * These differ whenever a level contributes nothing, and reading it off the
         * layers is wrong in a way that costs real money: an agency with no strategy of
         * its own produces a single level-0 layer, so the deepest layer is the Main
         * Office's, and cost would collapse to net — handing that agency the Main
         * Office's markup for free on every booking.
         */
        public int $bookerLevel = 0,
    ) {}

    /** A ladder with no rungs — every level contributed nothing, which is legitimate. */
    public static function unpriced(NetPrice $net, string $currency = 'PHP', int $bookerLevel = 0): self
    {
        return new self($net, [], new SellPrice($net->amount), Money::zero(), $currency, $bookerLevel);
    }

    /** Everything above the booker's own level: what the booking's wallet is debited. */
    public function cost(): Money
    {
        $cost = $this->net->amount;

        foreach ($this->layers as $layer) {
            if ($layer->level < $this->bookerLevel) {
                $cost = $cost->plus($layer->markup);
            }
        }

        return $cost;
    }

    /** Every level's markup together. */
    public function markupTotal(): Money
    {
        return $this->sell->amount->minus($this->net->amount);
    }

    /**
     * The booker's own margin — the SUM of their rungs, or nothing when they added none.
     *
     * A level contributes as many rungs as it has matching rules, so this is a sum and
     * not a lookup. Reading a single rung here would under-report an agency's margin the
     * moment it ran a base rate plus a service fee.
     */
    public function ownMargin(): Money
    {
        $margin = Money::zero();

        foreach ($this->layersAt($this->bookerLevel) as $layer) {
            $margin = $margin->plus($layer->markup);
        }

        return $margin;
    }

    /**
     * Every rung a level contributed, in the order they were applied.
     *
     * @return array<int, PricingLayer>
     */
    public function layersAt(int $level): array
    {
        return array_values(array_filter(
            $this->layers,
            fn (PricingLayer $layer): bool => $layer->level === $level,
        ));
    }

    /** The first rung at a level. Only meaningful where a level has exactly one. */
    public function layerAt(int $level): ?PricingLayer
    {
        return $this->layersAt($level)[0] ?? null;
    }

    /**
     * The whole ladder, unredacted — supplier net included.
     *
     * Only ever for someone entitled to see every rung: platform staff, the Main Office,
     * a margin report. Everything agency-facing goes through AgencyPriceView instead.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'net' => (string) $this->net->amount,
            'cost' => (string) $this->cost(),
            'sell' => (string) $this->sell->amount,
            'markupTotal' => (string) $this->markupTotal(),
            'roundingDelta' => (string) $this->roundingDelta,
            'layers' => array_map(fn (PricingLayer $l): array => $l->toArray(), $this->layers),
        ];
    }
}
