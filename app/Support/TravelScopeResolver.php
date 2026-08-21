<?php

namespace App\Support;

use App\Enums\TravelScope;

/**
 * The only place that decides whether something is domestic or international.
 *
 * It replaced three separate implementations that answered the same question three
 * ways: one matched the curated airport list on the country *name* "Philippines",
 * two compared TBO's 2-letter `CountryCode` against a config key. They agreed by
 * luck rather than by construction, and the first was the weakest — an airport nobody
 * had added yet read as international. That was harmless while the answer only
 * decorated a search request. It stops being harmless the moment price depends on it,
 * because a flight classified domestic in the results list and international at
 * checkout is a fare that changes under the agent.
 *
 * Everything here compares ISO 3166-1 alpha-2 codes, so the curated list and the
 * supplier speak the same language. `config/airports.php` carries `country_code` for
 * exactly this reason.
 *
 * **Unknown reads as international**, everywhere, deliberately — see TravelScope.
 *
 * Lives in Support rather than Services\Pricing because this is a geography question
 * with two consumers: the identity-document rules, which have asked it since before
 * pricing existed, and pricing, which will. A supplier service should not have to
 * reach into the pricing namespace to decide whether to ask for a passport.
 */
class TravelScopeResolver
{
    /** The country we sell from. */
    public static function pointOfSale(): string
    {
        return strtoupper((string) config('pricing.point_of_sale', 'PH'));
    }

    /**
     * Domestic only when every code given is the point of sale.
     *
     * An empty list is international: it means nothing could be read, not that nothing
     * left the country.
     *
     * @param  array<int, string|null>  $codes
     */
    public static function fromCountryCodes(array $codes): TravelScope
    {
        if ($codes === []) {
            return TravelScope::International;
        }

        $home = self::pointOfSale();

        foreach ($codes as $code) {
            if (strtoupper(trim((string) $code)) !== $home) {
                return TravelScope::International;
            }
        }

        return TravelScope::Domestic;
    }

    /**
     * One property, one country. Null — a hotel with no catalogue row behind it —
     * is unknown, so international.
     */
    public static function forCountryCode(?string $code): TravelScope
    {
        return self::fromCountryCodes([$code]);
    }

    /**
     * From bare IATA codes, for the search request — the one moment we have to answer
     * before the supplier has told us anything, so the curated list is all there is.
     *
     * @param  array<int, string|null>  $iataCodes
     */
    public static function forAirports(array $iataCodes): TravelScope
    {
        return self::fromCountryCodes(array_map(
            fn ($code): string => Airports::countryCode((string) $code) ?? '',
            $iataCodes,
        ));
    }

    /**
     * From legs already mapped by ItineraryMapper, which puts the airport's
     * `CountryCode` on both ends of every leg.
     *
     * @param  array<int, array<string, mixed>>  $legs
     */
    public static function forLegs(array $legs): TravelScope
    {
        $codes = [];

        foreach ($legs as $leg) {
            $codes[] = (string) data_get($leg, 'origin.country', '');
            $codes[] = (string) data_get($leg, 'destination.country', '');
        }

        return self::fromCountryCodes($codes);
    }

    /**
     * From raw TBO segments, for the Book payload, which reads the stored `quote_raw`
     * rather than anything this application shaped. Callers flatten the nesting first.
     *
     * @param  array<int, array<string, mixed>>  $segments
     */
    public static function forSegments(array $segments): TravelScope
    {
        $codes = [];

        foreach ($segments as $segment) {
            $codes[] = (string) data_get($segment, 'Origin.Airport.CountryCode', '');
            $codes[] = (string) data_get($segment, 'Destination.Airport.CountryCode', '');
        }

        return self::fromCountryCodes($codes);
    }
}
