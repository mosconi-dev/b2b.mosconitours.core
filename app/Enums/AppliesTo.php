<?php

namespace App\Enums;

/**
 * Which part of the supplier's rate a rule is charged on.
 *
 * Resolved by `PricingContext::basisFor()`, and only when the rule works from the
 * supplier net — a `running`-basis rule has no separable parts to narrow to.
 *
 * **The parts only exist on flights.** `PricingContextFactory::forFlightOffer()` sets
 * `baseFare` and `ancillaries`; `forHotelRoom()` sets neither, because a room rate
 * arrives as one number. The context falls back to the whole net when the narrower
 * figure is unknown — deliberately, since pricing a room at zero would be a silent
 * revenue loss — but that fallback makes a hotel rule read as though it excludes
 * something when it excludes nothing. Hence appliesToProduct(): the form refuses to
 * offer the choice where it would be a lie.
 */
enum AppliesTo: string
{
    /** Everything the supplier charges. */
    case Total = 'total';

    /** The fare before tax. */
    case BaseFare = 'base_fare';

    /** Everything except the extras the passenger added. */
    case ExclAncillaries = 'excl_ancillaries';

    public function label(): string
    {
        return match ($this) {
            self::Total => 'The whole supplier rate',
            self::BaseFare => 'Base fare only, before tax',
            self::ExclAncillaries => 'Everything except add-ons',
        };
    }

    /**
     * What it means, for whoever is choosing one.
     */
    public function guidance(): string
    {
        return match ($this) {
            self::Total => 'Everything the supplier charges — tax and extras included. '
                .'The default, and the only choice that means the same thing on every product.',

            self::BaseFare => 'The fare before tax. The industry norm on long-haul, where tax can '
                .'approach half the ticket and a percentage of the whole would price you out of the market.',

            self::ExclAncillaries => 'The fare without the baggage, meals and other extras the passenger '
                .'added. Use it when you take a margin on the travel and pass the extras through at cost.',
        };
    }

    /**
     * Whether this choice is honest on a given product.
     *
     * Flights only for the two narrow ones — see the class note. A rule matching EVERY
     * product gets neither, for the same reason: it would exclude tax on the flights it
     * matched and nothing on the hotels, from one rule that says it excludes tax.
     */
    public function appliesToProduct(string $product): bool
    {
        return $this === self::Total || $product === BookingProduct::Flight->value;
    }

    /** Why a choice is unavailable, for the form to say out loud. */
    public function productRestriction(): ?string
    {
        return $this === self::Total ? null : 'flights only';
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

    /**
     * @return array<string, string> value => label, for one product
     */
    public static function optionsForProduct(string $product): array
    {
        return array_reduce(
            array_filter(self::cases(), fn (self $case): bool => $case->appliesToProduct($product)),
            fn (array $carry, self $case): array => $carry + [$case->value => $case->label()],
            [],
        );
    }

    /**
     * Every product's allowed choices, for a form that switches between them.
     *
     * @return array<string, array<string, string>>
     */
    public static function optionsByProduct(): array
    {
        return array_reduce(
            array_merge(['*'], BookingProduct::values()),
            fn (array $carry, string $product): array => $carry + [$product => self::optionsForProduct($product)],
            [],
        );
    }
}
