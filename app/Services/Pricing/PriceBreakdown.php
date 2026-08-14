<?php

namespace App\Services\Pricing;

use App\Models\User;

/**
 * What the engine returns: a net price, the rungs added to it, and the selling price.
 *
 * Also the place price visibility is enforced. An Agency must never see the supplier's
 * net or the Main Office's markup as a separate figure, and search results travel to the
 * browser as JSON — so filtering in a Blade template is one devtools panel away from
 * useless. `forViewer()` collapses everything above the viewer's own level into a single
 * opaque "cost" and drops the rest BEFORE serialization.
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
    ) {}

    /** A ladder with no rungs — every level contributed nothing, which is legitimate. */
    public static function unpriced(NetPrice $net, string $currency = 'PHP'): self
    {
        return new self($net, [], new SellPrice($net->amount), Money::zero(), $currency);
    }

    /** Everything above the booker's own level: what the booking's wallet is debited. */
    public function cost(): Money
    {
        $ownLevel = $this->ownLevel();

        $cost = $this->net->amount;

        foreach ($this->layers as $layer) {
            if ($layer->level < $ownLevel) {
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

    /** The booker's own margin — the deepest rung, which is theirs. */
    public function ownMargin(): Money
    {
        $deepest = $this->layers === [] ? null : $this->layers[array_key_last($this->layers)];

        return $deepest?->markup ?? Money::zero();
    }

    public function layerAt(int $level): ?PricingLayer
    {
        foreach ($this->layers as $layer) {
            if ($layer->level === $level) {
                return $layer;
            }
        }

        return null;
    }

    /**
     * The booker's own level — the deepest rung on the ladder.
     *
     * With no rungs at all there is nothing above the booker, so cost is net.
     */
    private function ownLevel(): int
    {
        return $this->layers === []
            ? 0
            : $this->layers[array_key_last($this->layers)]->level;
    }

    /**
     * The whole ladder. Only ever for someone entitled to see every rung — the Main
     * Office, the ladder preview, a margin report.
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

    /**
     * The ladder as this viewer is allowed to see it.
     *
     * Platform staff and Main Office members see everything. Anyone else sees their cost
     * as ONE opaque number, their own markup, and the selling price — never the supplier
     * net, and never an upstream level's margin broken out.
     *
     * This is a security boundary, not a presentation choice: the return value is what
     * goes into the JSON, so what is dropped here is what the browser never receives.
     *
     * @return array<string, mixed>
     */
    public function forViewer(?User $viewer): array
    {
        if ($viewer !== null && $viewer->isPlatformStaff()) {
            return $this->toArray();
        }

        $ownLevel = $this->ownLevel();
        $own = $this->layerAt($ownLevel);

        // A Main Office member is at level 0 and has nothing above them, so their cost
        // is the net — which is theirs to see.
        $seesNet = $ownLevel === 0;

        return [
            'currency' => $this->currency,
            'cost' => (string) $this->cost(),
            'markup' => (string) ($own?->markup ?? Money::zero()),
            'sell' => (string) $this->sell->amount,
            // Their own rung only, and redacted: toArray() carries `basisAmount`, which
            // IS the supplier net on a net-basis rule. Upstream rungs are not summarised,
            // not labelled and not counted — how many levels there are is itself
            // commercial information.
            'ownLayer' => $own?->toViewerArray(),
            'net' => $seesNet ? (string) $this->net->amount : null,
        ];
    }
}
