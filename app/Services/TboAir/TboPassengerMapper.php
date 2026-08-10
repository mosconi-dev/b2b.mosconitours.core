<?php

namespace App\Services\TboAir;

use InvalidArgumentException;

/**
 * Encodes our passenger strings as the integers TBO's Book/Ticket methods expect.
 *
 * We store human-readable values ("Mr", "Adult", "F") because that is what the UI and
 * every report want. TBO's Book passenger array wants enum ordinals. This is the
 * translation at the supplier boundary — the only place those integers should appear.
 *
 * Phase 4.1 assembles the full Book payload; this covers the encoding it will need.
 */
class TboPassengerMapper
{
    /**
     * Title → TBO ordinal. TBO offers exactly three: Mr=0, Miss=1, Mrs=2.
     *
     * "Ms" and "Mstr" were selectable in the booking wizard before Phase 4.0 and may
     * sit on stored bookings, so they are folded onto the nearest TBO value rather
     * than rejected — Ms to Miss, and Mstr (a young boy) to Mr, which is the only
     * male option TBO has.
     */
    public static function title(string $title): int
    {
        return match (strtolower(trim($title))) {
            'mr', 'mstr', 'master' => 0,
            'miss', 'ms' => 1,
            'mrs' => 2,
            default => throw new InvalidArgumentException("Unmappable passenger title [{$title}]."),
        };
    }

    /**
     * Gender → TBO ordinal (Male=1, Female=2).
     *
     * Gender is nullable on our side but mandatory for TBO, so an absent value is an
     * error here rather than a silent default — guessing would put the wrong marker on
     * a ticket, and airlines match it against ID.
     */
    public static function gender(?string $gender): int
    {
        return match (strtolower(trim((string) $gender))) {
            'm', 'male' => 1,
            'f', 'female' => 2,
            default => throw new InvalidArgumentException('A passenger gender is required before booking.'),
        };
    }

    /**
     * Passenger type → TBO ordinal. TBO also defines Senior=4 and Youth=5; we do not
     * sell those fare types, so they are deliberately absent.
     */
    public static function paxType(string $type): int
    {
        return match (strtolower(trim($type))) {
            'adult' => 1,
            'child' => 2,
            'infant' => 3,
            default => throw new InvalidArgumentException("Unmappable passenger type [{$type}]."),
        };
    }
}
