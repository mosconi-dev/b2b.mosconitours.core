<?php

namespace App\Enums;

/**
 * How a pricing rule turns a basis amount into a markup.
 *
 * The engine never branches on this. Each case maps to a Calculator class through
 * CalculatorRegistry, so adding a type is registering a class — not editing the engine,
 * the resolver, the matcher or the layers table.
 *
 * Only Fixed and PercentageMarkup are implemented. The rest are declared because the
 * shape of the set is a business decision already taken, and a half-declared enum
 * invites someone to invent a seventh spelling of "percentage".
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
        return [self::Fixed, self::PercentageMarkup];
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
