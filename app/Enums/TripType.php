<?php

namespace App\Enums;

enum TripType: string
{
    case Round = 'round';
    case OneWay = 'oneway';
    case Multi = 'multi';

    /**
     * Map to the TBO Air "JourneyType" value.
     * 1 = OneWay, 2 = Return, 3 = MultiStop.
     */
    public function journeyType(): int
    {
        return match ($this) {
            self::OneWay => 1,
            self::Round => 2,
            self::Multi => 3,
        };
    }
}
