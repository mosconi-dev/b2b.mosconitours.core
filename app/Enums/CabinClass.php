<?php

namespace App\Enums;

enum CabinClass: string
{
    case Any = 'any';
    case Economy = 'economy';
    case Premium = 'premium';   // Premium Economy
    case Business = 'business';
    case First = 'first';

    /**
     * Map to the TBO Air "FlightCabinClass" value.
     * 1 = All, 2 = Economy, 3 = PremiumEconomy, 4 = Business, 6 = First.
     */
    public function tboCode(): int
    {
        return match ($this) {
            self::Any => 1,
            self::Economy => 2,
            self::Premium => 3,
            self::Business => 4,
            self::First => 6,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Any => 'Any Class',
            self::Economy => 'Economy',
            self::Premium => 'Premium Economy',
            self::Business => 'Business',
            self::First => 'First Class',
        };
    }
}
