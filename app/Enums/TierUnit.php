<?php

namespace App\Enums;

use App\Services\Pricing\PricingContext;

/**
 * What a tier table measures — the whole booking, or one unit of it.
 *
 * A ₱30,000 fare for three passengers is either a ₱30,000 booking or three ₱10,000
 * tickets, and a band table means something different in each reading. The air trade
 * reads it the second way: a tier table is a per-ticket table, so three seats at ₱10,000
 * are each priced as a ₱10,000 ticket rather than as one expensive booking that has
 * climbed out of the band it belongs in.
 *
 * Per-unit banding prices ONE unit from the unit fare and multiplies back up, so a flat
 * band means "₱800 a ticket" — the natural reading, and the only one that makes a flat
 * band useful here.
 *
 * The unit belongs to the product, exactly as it does for CalcType::PerPax and
 * PerRoomNight: head count is not what a hotel charges on, and nights are not what an
 * airline charges on. appliesToProduct() is what keeps a rule from claiming otherwise.
 */
enum TierUnit: string
{
    case Booking = 'booking';
    case Passenger = 'passenger';
    case RoomNight = 'room_night';

    public function label(): string
    {
        return match ($this) {
            self::Booking => 'The whole booking',
            self::Passenger => "Each passenger's share",
            self::RoomNight => "Each room-night's share",
        };
    }

    public function guidance(): string
    {
        return match ($this) {
            self::Booking => 'Bands read the total. Three seats at 10,000 are a 30,000 booking, so the '
                .'table is read at 30,000.',

            self::Passenger => 'Bands read one ticket. Three seats at 10,000 are read at 10,000 each, '
                .'priced as a 10,000 ticket, and multiplied back up — which is what a tier table means in '
                .'the air trade, and what stops a family of five paying the long-haul rate on a domestic '
                .'fare. A flat band becomes an amount per ticket.',

            self::RoomNight => 'Bands read one room for one night. Two rooms for three nights are read at '
                .'a sixth of the rate each and multiplied back up. The axis a hotel rate actually moves '
                .'on — head count deliberately does not enter into it, because the supplier does not '
                .'charge on it.',
        };
    }

    /**
     * The unit as a suffix on an amount — "12% / 8% / 5%, per passenger". Null for the
     * whole booking, which needs no saying.
     */
    public function shortLabel(): ?string
    {
        return match ($this) {
            self::Booking => null,
            self::Passenger => 'per passenger',
            self::RoomNight => 'per room-night',
        };
    }

    /** "3 passengers", "6 room-nights" — for spelling out a worked example. */
    public function countLabel(int $count): string
    {
        $noun = match ($this) {
            self::Booking => 'booking',
            self::Passenger => 'passenger',
            self::RoomNight => 'room-night',
        };

        return $count.' '.($count === 1 ? $noun : $noun.'s');
    }

    /**
     * How many of these a booking holds. Never below one: a table read at a zero fare
     * would band every booking in its cheapest rung.
     */
    public function unitsIn(PricingContext $context): int
    {
        return match ($this) {
            self::Booking => 1,
            self::Passenger => max(1, $context->paxCount),
            self::RoomNight => max(1, $context->roomCount * $context->nights),
        };
    }

    /**
     * Whether this unit means anything on a product. The whole booking always does; the
     * other two are the product's own axis and nothing else's.
     *
     * A rule matching EVERY product gets neither, for the reason CalcType gives: one rule
     * cannot divide by head count on the flights it matches and by room-nights on the
     * hotels, from a single line that says it divides by one of them.
     */
    public function appliesToProduct(string $product): bool
    {
        return match ($this) {
            self::Booking => true,
            self::Passenger => $product === BookingProduct::Flight->value,
            self::RoomNight => $product === BookingProduct::Hotel->value,
        };
    }

    /** Why a unit is unavailable, for the form to say out loud. */
    public function productRestriction(): ?string
    {
        return match ($this) {
            self::Booking => null,
            self::Passenger => 'flights only',
            self::RoomNight => 'hotels only',
        };
    }

    /**
     * What a table on this product should be read at unless somebody says otherwise.
     *
     * A flight defaults to the per-ticket reading because that is what a tier table means
     * to the people writing one. Everything else falls back to the total, which is the
     * only reading that means the same thing on every product.
     */
    public static function defaultFor(string $product): self
    {
        return self::Passenger->appliesToProduct($product) ? self::Passenger : self::Booking;
    }

    /**
     * Every product's default reading, for a form that switches between them.
     *
     * @return array<string, string>
     */
    public static function defaultsByProduct(): array
    {
        return array_reduce(
            array_merge(['*'], BookingProduct::values()),
            fn (array $carry, string $product): array => $carry + [$product => self::defaultFor($product)->value],
            [],
        );
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
     * Every product's units, for a form that switches between them without a round trip.
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
