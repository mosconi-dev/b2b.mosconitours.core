<?php

namespace App\Services\Pricing;

use App\Models\User;

/**
 * The EXTERNAL representation of a price — the only shape that may reach an agency.
 *
 * The platform keeps two models of the same price, and they are deliberately different
 * objects rather than two methods on one:
 *
 *   PriceBreakdown    INTERNAL. The full ladder — supplier net, every level's rule and
 *                     contribution, cost, selling price. The engine computes it, the
 *                     booking spine writes it to `booking_price_layers`, and margin
 *                     reporting reads it back. It is the audit record and it is never
 *                     serialized to an agency.
 *
 *   AgencyPriceView   EXTERNAL. Built FROM a PriceBreakdown for one named viewer, and
 *                     carrying only what that viewer is authorized to see.
 *
 * Keeping them apart is the point. A single object that is sometimes redacted gets
 * serialized unredacted by the next endpoint somebody writes; a separate type means the
 * internal one has to be reached for on purpose.
 *
 * **What an agency may never receive:** the supplier net, the Main Office's markup
 * amount, its percentage, its rule, its strategy, or how many levels sit above it.
 * Everything above the agency arrives fused into a single opaque `cost`.
 *
 * Levels ADD and do not compound: both the Main Office and an agency take their
 * percentage of the supplier net. That is the business rule and this class does not
 * change it — it governs what is *shown*, never what is *charged*.
 */
final readonly class AgencyPriceView
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(private array $payload) {}

    /**
     * A price with a REAL supplier rate behind it — a search result, a re-quote, a room.
     *
     * An agency sees its OWN POSITION on the fare: what it pays, what it earns, what the
     * customer pays, and which of its own rules produced the margin. An agent quoting a
     * customer needs that at the point of sale, and it is theirs.
     *
     * **What it still never sees:** the supplier net, the Main Office's markup as a
     * figure, its rules, its percentage, or its strategy. `cost` is one opaque number —
     * net and the office's cut fused together, with nothing to separate them.
     *
     * What this DOES concede, knowingly: an agency that knows the percentage it
     * configured can divide its own margin back to the supplier net, and subtract to
     * reach the office's cut. That inference was accepted on 15 August 2026 — the
     * business decided an agent seeing its own margin on a live fare is worth more than
     * closing an arithmetic gap that an agency writing its own rules can close anyway.
     * See D12. What is refused is *handing over* the net, not the arithmetic.
     */
    public static function forOffer(PriceBreakdown $price, ?User $viewer): self
    {
        if (self::isEntitledToNet($price, $viewer)) {
            return new self($price->toArray());
        }

        return new self([
            'currency' => $price->currency,
            // net + every level above the booker, fused. Never broken out: how many
            // levels there are, and what each took, stays the platform's business.
            'cost' => (string) $price->cost(),
            'markup' => (string) $price->ownMargin(),
            'sell' => (string) $price->sell->amount,
            // Their own rungs only — which of THEIR rules produced that margin.
            'ownLayers' => array_map(
                fn (PricingLayer $l): array => $l->toViewerArray(),
                $price->layersAt($price->bookerLevel),
            ),
        ]);
    }

    /**
     * The agency's own ladder preview, against a figure the agency typed itself.
     *
     * Nothing here is secret: the viewer supplied the number, so showing what their own
     * rule does to it discloses nothing about any real supplier rate. This is the
     * channel that makes forOffer()'s silence affordable — an agency can still see and
     * check its own pricing, it just cannot read it off a live fare.
     *
     * The Main Office rung is dropped even though the engine computed it. Running the
     * whole chain and showing `cost` would hand over the Main Office's markup as the
     * difference between the typed figure and the cost — which is exactly the amount
     * that must stay opaque. So `cost` here is the agency's own input, and the selling
     * price is that input plus the agency's own rung.
     */
    public static function forOwnLadder(PriceBreakdown $price, ?User $viewer, Money $basis): self
    {
        if (self::isEntitledToNet($price, $viewer)) {
            return new self($price->toArray());
        }

        $own = $price->layersAt($price->bookerLevel);
        $markup = $price->ownMargin();

        return new self([
            'currency' => $price->currency,
            'cost' => (string) $basis,
            'markup' => (string) $markup,
            'sell' => (string) $basis->plus($markup),
            // Their own rungs only — every rule of theirs that fired, since a level is
            // cumulative — and already redacted: toViewerArray() drops the basis amount,
            // which is the supplier net on a net-basis rule.
            'ownLayers' => array_map(fn (PricingLayer $l): array => $l->toViewerArray(), $own),
        ]);
    }

    /**
     * Who may see the supplier's own number.
     *
     * Platform staff, and a viewer sitting at the top of the ladder — the Main Office
     * has nothing above it, so its cost IS the net and withholding it would be hiding
     * their own figure from them.
     */
    private static function isEntitledToNet(PriceBreakdown $price, ?User $viewer): bool
    {
        return $viewer?->isPlatformStaff() === true || $price->bookerLevel === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
