<?php

namespace App\Enums;

/**
 * How a tier table applies its bands — and the difference between a table that works and
 * one that quietly loses money.
 *
 * `whole` is what the words usually say: a ₱30,000 fare is "in the 8% band", so it is
 * charged 8% of ₱30,000. It is also where the cliff lives — 12% of ₱10,000 is ₱1,200 and
 * 8% of ₱10,001 is ₱800, so a dearer fare sells for less. TieredBands refuses a table
 * that does that.
 *
 * `marginal` is what tax brackets do: each rate applies only to the slice of the fare
 * inside its own band. The classic 12/8/5 table is only writable this way, because in
 * whole mode it falls at every boundary. It cannot invert — every extra peso adds a
 * non-negative amount — so it needs no cliff check at all.
 */
enum TierMode: string
{
    case Whole = 'whole';
    case Marginal = 'marginal';

    public function label(): string
    {
        return match ($this) {
            self::Whole => 'The band the fare lands in charges the whole fare',
            self::Marginal => 'Each band charges only its own slice of the fare',
        };
    }

    public function guidance(): string
    {
        return match ($this) {
            self::Whole => 'A fare of 30,000 lands in the 10,000–50,000 band and is charged that band\'s '
                .'rate on the whole 30,000. The plain reading of a tier table — but watch the boundaries: '
                .'if a band charges less than the one below it, a dearer fare can sell for less, and that '
                .'is refused.',

            self::Marginal => 'The way tax brackets work: the first 10,000 is charged at the first band\'s '
                .'rate, the next 40,000 at the second\'s, and so on. A fare can never sell for less than a '
                .'cheaper one, so rates that fall as the fare climbs — 12%, then 8%, then 5% — are only '
                .'writable this way.',
        };
    }

    public static function default(): self
    {
        return self::Whole;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $case): array => $carry + [$case->value => $case->label()],
            [],
        );
    }
}
