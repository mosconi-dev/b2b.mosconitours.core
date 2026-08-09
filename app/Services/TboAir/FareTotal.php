<?php

namespace App\Services\TboAir;

/**
 * The trip total for a single TBO result.
 *
 * TBO intermittently returns a result whose headline `Fare` block is blanked —
 * no `OfferedFare` or `PublishedFare` key at all, and `Tax` reset to 0 — while the
 * `FareBreakdown` on that same result still carries the real numbers. Reading the
 * headline key alone showed those fares as "PHP 0" in the results list and would
 * have written a 0 total onto the booking, so fall through the alternatives
 * instead of trusting one key.
 */
class FareTotal
{
    /**
     * @param  array<string, mixed>  $result  one entry from Results (search or FareQuote)
     */
    public static function for(array $result): float
    {
        $fare = data_get($result, 'Fare', []);

        // data_get's default only covers a *missing* key, and a present-but-zero
        // fare is just as unusable, so test the values rather than the keys.
        $offered = (float) data_get($fare, 'OfferedFare', 0);
        if ($offered > 0) {
            return $offered;
        }

        $published = (float) data_get($fare, 'PublishedFare', 0);
        if ($published > 0) {
            return $published;
        }

        // FareBreakdown rows are group totals per passenger type — a row of 3 adults
        // holds the fare for all three — so summing the rows gives the trip total.
        // On healthy responses that sum reproduces PublishedFare exactly.
        $breakdown = 0.0;
        foreach ((array) data_get($result, 'FareBreakdown', []) as $row) {
            $breakdown += (float) data_get($row, 'BaseFare', 0) + (float) data_get($row, 'Tax', 0);
        }

        if ($breakdown > 0) {
            return $breakdown;
        }

        return (float) data_get($fare, 'BaseFare', 0) + (float) data_get($fare, 'Tax', 0);
    }
}
