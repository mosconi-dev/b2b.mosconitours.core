<?php

namespace App\Enums;

/**
 * Whether what is being sold stays inside the country we sell from.
 *
 * One concept, two consumers. It already decides which identity document a passenger
 * is asked for — a passport internationally, any government ID domestically — and it
 * is the coarsest axis a pricing rule matches on, because a domestic hop and a
 * long-haul ticket do not carry the same margin.
 *
 * **Unknown reads as international.** A missing country code makes the answer
 * unknowable, and guessing "domestic" would downgrade a passport to an ID and price a
 * long-haul fare as a hop. International is the direction that fails safe on both.
 *
 * Resolved by App\Support\TravelScopeResolver, which is the only place that decides.
 */
enum TravelScope: string
{
    case Domestic = 'domestic';
    case International = 'international';

    public function label(): string
    {
        return match ($this) {
            self::Domestic => 'Domestic',
            self::International => 'International',
        };
    }

    public function isDomestic(): bool
    {
        return $this === self::Domestic;
    }

    /**
     * Tailwind pill classes for rendering the scope as a badge, following AgencyType.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Domestic => 'bg-teal-50 text-teal-700 ring-teal-600/20',
            self::International => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
