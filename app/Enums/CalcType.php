<?php

namespace App\Enums;

/**
 * How a pricing rule turns a basis amount into a markup.
 *
 * The engine never branches on this. Each case maps to a Calculator class through
 * CalculatorRegistry, so adding a type is registering a class — not editing the engine,
 * the resolver, the matcher or the layers table.
 *
 * Every case but Tiered has a calculator. Tiered stays declared and unimplemented
 * because it carries bands rather than a single number, and `pricing_rules.value` is one
 * `decimal(12,4)` — it needs a `params` column before it can be anything but an enum
 * case. The case is kept regardless: the shape of the set is a business decision already
 * taken, and a half-declared enum invites someone to invent a seventh spelling of
 * "percentage".
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

    /** Whether the rule's `value` means anything. It does not for an explicit zero. */
    public function usesValue(): bool
    {
        return $this !== self::None;
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
