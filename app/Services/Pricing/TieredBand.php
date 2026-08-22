<?php

namespace App\Services\Pricing;

use App\Enums\CalcType;
use App\Services\Pricing\Exceptions\PricingException;

/**
 * One rung of a tier table: everything up to an amount is charged this way.
 *
 * A band's arithmetic lives here rather than being delegated back to the registry,
 * because the validator needs to compute a band's markup with no booking in front of it
 * — that is how it checks a table for the cliff described on TieredBands. Only the
 * context-free types are allowed in a band, which is what makes that check exhaustive.
 */
final class TieredBand
{
    /**
     * The calculation types a band may use.
     *
     * Deliberately a subset. `per_pax` and `per_room_night` depend on the booking, so a
     * table using them could not be checked for inversions at the point somebody writes
     * it — and an unverifiable tier table is the thing this whole class exists to
     * prevent. Charge per passenger with a second rule instead; contributions are
     * cumulative, so it adds on top.
     *
     * @return array<int, CalcType>
     */
    public static function allowedTypes(): array
    {
        return [CalcType::Fixed, CalcType::PercentageMarkup, CalcType::PercentageMargin, CalcType::None];
    }

    public function __construct(
        /** The top of this band, inclusive. Null on the last band: everything above. */
        public readonly ?Money $upTo,
        public readonly CalcType $calcType,
        public readonly string $value,
    ) {}

    /**
     * Whether a basis amount falls in this band. The upper bound is INCLUSIVE — a fare
     * of exactly ₱10,000 is priced by the "up to 10,000" band, not the one above it.
     */
    public function covers(Money $basis): bool
    {
        return $this->upTo === null || ! $basis->greaterThan($this->upTo);
    }

    public function markupOn(Money $basis): Money
    {
        return match ($this->calcType) {
            CalcType::Fixed => Money::of($this->value),
            CalcType::PercentageMarkup => $basis->percent($this->value),
            CalcType::PercentageMargin => $basis->margin($this->value),
            CalcType::None => Money::zero(),
            default => throw new PricingException(
                "A tier band cannot be charged '{$this->calcType->value}' — it depends on the booking, "
                .'so the table could not be checked when it was written.'
            ),
        };
    }

    /** "12%" or "800.00", in the same words the rule list uses for a whole rule. */
    public function amountLabel(): string
    {
        return $this->calcType->describeAmount($this->value);
    }

    /**
     * Which fares this band prices, in words — "up to 10,000.00", "10,000.00–50,000.00",
     * "above 50,000.00". The band below is passed in because a band does not know its
     * own predecessor, and "above that" has no referent on a rung of a priced booking.
     */
    public function rangeLabel(?Money $from): string
    {
        $lower = $from === null ? null : number_format($from->toFloat(), 2);
        $upper = $this->upTo === null ? null : number_format($this->upTo->toFloat(), 2);

        return match (true) {
            $lower === null => "up to {$upper}",
            $upper === null => "above {$lower}",
            default => "{$lower}–{$upper}",
        };
    }
}
