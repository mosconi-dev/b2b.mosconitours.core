<?php

namespace App\Enums;

/**
 * How a pricing rule turns a basis amount into a markup.
 *
 * The engine never branches on this. Each case maps to a Calculator class through
 * CalculatorRegistry, so adding a type is registering a class — not editing the engine,
 * the resolver, the matcher or the layers table.
 *
 * Every case has a calculator. Tiered is the one that carries bands rather than a single
 * number, so it reads `pricing_rules.params` and ignores `value` entirely — see
 * TieredBands, which owns the table, its arithmetic and the check that refuses a table
 * where a more expensive fare would sell for less.
 */
enum CalcType: string
{
    case Fixed = 'fixed';
    case PercentageMarkup = 'percentage_markup';
    case PercentageMargin = 'percentage_margin';
    case PerPax = 'per_pax';
    case PerRoomNight = 'per_room_night';
    case Tiered = 'tiered';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed amount',
            self::PercentageMarkup => 'Percentage markup',
            self::PercentageMargin => 'Percentage margin',
            self::PerPax => 'Per passenger',
            self::PerRoomNight => 'Per room night',
            self::Tiered => 'Tiered by amount',
            self::None => 'No markup',
        };
    }

    /**
     * Whether `value` is a percentage rather than an amount — decides how the admin
     * screens and the ladder preview render it.
     */
    public function isPercentage(): bool
    {
        return in_array($this, [self::PercentageMarkup, self::PercentageMargin], true);
    }

    /**
     * What the amount is charged *per*, for the rule list. Null when it is charged once.
     *
     * Without it a ₱500 booking fee and a ₱500 per-passenger fee are the same three
     * characters on a pricing screen, and only one of them costs a family of five
     * ₱2,500.
     */
    public function unitLabel(): ?string
    {
        return match ($this) {
            self::PerPax => 'per passenger',
            self::PerRoomNight => 'per room-night',
            default => null,
        };
    }

    /**
     * What a rule of this type adds, as a phrase — "10%", "350.00 per passenger",
     * "No markup".
     *
     * The one home for this vocabulary. The rule list, the ladder preview and the
     * agency's own panel all render it, and the two previews used to decide it in
     * JavaScript from `calcType` — so every type added here was silently labelled "flat"
     * in the browser until somebody noticed. Deciding it server-side means a new type is
     * still just a class and a registry line.
     */
    public function describeAmount(string|float|int|null $value): string
    {
        if (! $this->usesValue()) {
            return $this->label();
        }

        $amount = $this->isPercentage()
            ? self::trimPercent($value).'%'
            : number_format((float) $value, 2);

        $unit = $this->unitLabel();

        return $unit === null ? $amount : "{$amount} {$unit}";
    }

    /**
     * A percentage without its trailing zeros: 8.0000 reads as 8, 7.5000 as 7.5.
     *
     * Normalised to four places FIRST, which is the whole job. Trimming zeros off a bare
     * "20" takes the zero that belongs to the twenty and prints 2% — invisible while the
     * only caller was an Eloquent `decimal:4` attribute that always carried a point, and
     * a wrong number on a pricing screen the moment anything else called it.
     */
    private static function trimPercent(string|float|int|null $value): string
    {
        $trimmed = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }

    /** Whether the rule's `value` means anything. It does not for an explicit zero. */
    public function usesValue(): bool
    {
        return ! in_array($this, [self::None, self::Tiered], true);
    }

    /**
     * What this type is, in a sentence, for whoever is choosing one.
     *
     * Written for an office or agency user rather than a developer: what it does, and
     * when you would reach for it. The arithmetic is NOT spelled out here — CalcTypeGuide
     * runs the real calculator to produce a worked example, so the numbers beside this
     * sentence can never disagree with what a booking is charged.
     */
    public function guidance(): string
    {
        return match ($this) {
            self::Fixed => 'The same amount on every booking, whatever the fare costs. '
                .'The right shape for thin-margin inventory, where a percentage that suits a full-service '
                .'international ticket would price a budget domestic seat out of the market.',

            self::PercentageMarkup => 'A percentage of what the supplier charges. '
                .'The usual choice for hotels and full-service fares, because the margin grows with the '
                .'value of what is being sold.',

            self::PercentageMargin => 'A percentage of the price the customer pays, not of the cost. '
                .'Reach for this when the figure you have been given is a share of the SELLING price — '
                .'it is what a finance team usually means by a margin, and it always adds more than a '
                .'markup at the same number.',

            self::PerPax => 'A fixed amount for every passenger on the booking. '
                .'The air trade norm — a service fee per ticket issued — so a family of five pays five.',

            self::PerRoomNight => 'A fixed amount for every room, every night. '
                .'The axis a hotel rate actually moves on: two rooms for three nights is six of these. '
                .'Head count is deliberately not a factor, because the supplier does not charge on it.',

            self::Tiered => 'A different charge for each band of supplier rate, so the margin is protected '
                .'on cheap fares without pricing long-haul out of the market. Choose whether the band a fare '
                .'lands in charges the whole fare, or whether each band charges only its own slice the way '
                .'tax brackets do. Rates that FALL as the fare climbs — 12%, then 8%, then 5% — are only '
                .'writable by slice: on the whole fare they would make a dearer booking sell for less, and '
                .'that is refused. Bands read the whole booking, never per passenger.',

            self::None => 'Takes nothing, on purpose. '
                .'Use it for a negotiated corporate rate or a staff booking, so the list says somebody '
                .'decided to pass this through at cost — rather than leaving a gap that looks like a rule '
                .'nobody got round to writing.',
        };
    }

    /**
     * Whether this type can be charged on a given product.
     *
     * The unit a fee scales on is a property of the product, not a preference. A hotel
     * rate is per room per night and a fare is per passenger, and charging either on the
     * other's axis grows the fee with a number the supplier never charged on — the live
     * system multiplied hotel markup by head count, so two adults in one double room paid
     * one room rate and two markups.
     *
     * A rule matching **every** product (`'*'`) gets neither per-unit type, because
     * neither means the same thing on both sides of that wildcard. Say which product it
     * is, and the unit becomes available.
     *
     * This is advisory in the form and binding in StorePricingRuleRequest. The form can
     * be bypassed; the validator cannot.
     */
    public function appliesToProduct(string $product): bool
    {
        return match ($this) {
            self::PerPax => $product === BookingProduct::Flight->value,
            self::PerRoomNight => $product === BookingProduct::Hotel->value,
            default => true,
        };
    }

    /**
     * Why a type is missing from a product's list, for the form to say out loud.
     *
     * A greyed-out option with no explanation reads as a bug. Naming the restriction
     * turns it into a decision somebody made.
     */
    public function productRestriction(): ?string
    {
        return match ($this) {
            self::PerPax => 'flights only',
            self::PerRoomNight => 'hotels only',
            default => null,
        };
    }

    /**
     * The implemented types this product may use.
     *
     * @return array<int, self>
     */
    public static function forProduct(string $product): array
    {
        return array_values(array_filter(
            self::implemented(),
            fn (self $type): bool => $type->appliesToProduct($product),
        ));
    }

    /**
     * @return array<string, string> value => label, for one product's select
     */
    public static function optionsForProduct(string $product): array
    {
        return array_reduce(
            self::forProduct($product),
            fn (array $carry, self $type): array => $carry + [$type->value => $type->label()],
            [],
        );
    }

    /**
     * Every product's allowed types, for a form that has to switch between them without
     * a round trip.
     *
     * @return array<string, array<string, string>>
     */
    public static function optionsByProduct(): array
    {
        $products = array_merge(['*'], BookingProduct::values());

        return array_reduce(
            $products,
            fn (array $carry, string $product): array => $carry + [$product => self::optionsForProduct($product)],
            [],
        );
    }

    /**
     * The types with a Calculator behind them today.
     *
     * Anything else is refused at validation rather than at quote time: a rule that
     * cannot be computed is worse saved than rejected, because it fails on a live
     * search instead of in the form that created it.
     *
     * @return array<int, self>
     */
    public static function implemented(): array
    {
        return [
            self::Fixed,
            self::PercentageMarkup,
            self::PercentageMargin,
            self::PerPax,
            self::PerRoomNight,
            self::Tiered,
            self::None,
        ];
    }

    /**
     * @return array<string, string> value => label, for selects
     */
    public static function options(): array
    {
        return array_reduce(
            self::implemented(),
            fn (array $carry, self $type): array => $carry + [$type->value => $type->label()],
            [],
        );
    }
}
