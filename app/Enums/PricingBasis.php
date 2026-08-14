<?php

namespace App\Enums;

/**
 * What a rule's percentage is a percentage *of*.
 *
 * The single most consequential switch in pricing, and the reason it is a required
 * column with no default. Two 10% rules over a ₱5,000 net:
 *
 *   Net     Main Office 10% of 5,000 = 500;  Agency 10% of 5,000 = 500  → 6,000
 *   Running Main Office 10% of 5,000 = 500;  Agency 10% of 5,500 = 550  → 6,050
 *
 * Both are legitimate policies. Neither should ever be an accident, so the schema
 * refuses to guess.
 *
 * `Net` is the house default: it does not compound, and because addition commutes the
 * total does not depend on which level is evaluated first.
 */
enum PricingBasis: string
{
    /** Always the supplier's rate, whatever the levels above have already added. */
    case Net = 'net';

    /** The price as it stands after the levels above — compounds. */
    case Running = 'running';

    public function label(): string
    {
        return match ($this) {
            self::Net => 'Supplier net',
            self::Running => 'Running total',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Net => 'A percentage of the supplier rate. Levels do not compound.',
            self::Running => 'A percentage of the price after the levels above. Compounds.',
        };
    }

    /**
     * @return array<string, string> value => label, for selects
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $basis): array => $carry + [$basis->value => $basis->label()],
            [],
        );
    }
}
