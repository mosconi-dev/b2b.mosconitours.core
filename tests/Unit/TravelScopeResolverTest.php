<?php

namespace Tests\Unit;

use App\Enums\TravelScope;
use App\Support\Airports;
use App\Support\TravelScopeResolver;
use Tests\TestCase;

/**
 * The classifier that replaced three separate implementations of "is this domestic?".
 *
 * The cases that matter most are the unknown ones: every one of them must come back
 * International, because the alternative — guessing domestic — downgrades a passport to
 * a government ID and prices a long-haul fare as a hop.
 */
class TravelScopeResolverTest extends TestCase
{
    public function test_point_of_sale_comes_from_the_shared_config_key(): void
    {
        $this->assertSame('PH', TravelScopeResolver::pointOfSale());

        config(['pricing.point_of_sale' => 'sg']);

        $this->assertSame('SG', TravelScopeResolver::pointOfSale(), 'normalised to upper case');
    }

    public function test_all_home_codes_are_domestic(): void
    {
        $this->assertSame(TravelScope::Domestic, TravelScopeResolver::fromCountryCodes(['PH', 'PH', 'PH']));
    }

    public function test_one_foreign_code_makes_the_whole_itinerary_international(): void
    {
        $this->assertSame(TravelScope::International, TravelScopeResolver::fromCountryCodes(['PH', 'PH', 'SG', 'PH']));
    }

    public function test_an_empty_list_is_international(): void
    {
        // Nothing could be read, which is not the same as nothing leaving the country.
        $this->assertSame(TravelScope::International, TravelScopeResolver::fromCountryCodes([]));
    }

    public function test_blank_and_null_codes_are_international(): void
    {
        $this->assertSame(TravelScope::International, TravelScopeResolver::fromCountryCodes(['PH', '']));
        $this->assertSame(TravelScope::International, TravelScopeResolver::fromCountryCodes(['PH', null]));
    }

    public function test_codes_are_compared_case_and_whitespace_insensitively(): void
    {
        $this->assertSame(TravelScope::Domestic, TravelScopeResolver::fromCountryCodes([' ph ', 'Ph']));
    }

    public function test_the_point_of_sale_decides_which_country_is_home(): void
    {
        config(['pricing.point_of_sale' => 'SG']);

        $this->assertSame(TravelScope::Domestic, TravelScopeResolver::fromCountryCodes(['SG']));
        $this->assertSame(TravelScope::International, TravelScopeResolver::fromCountryCodes(['PH']));
    }

    public function test_airports_are_resolved_through_the_curated_list(): void
    {
        $this->assertSame(TravelScope::Domestic, TravelScopeResolver::forAirports(['MNL', 'CEB']));
        $this->assertSame(TravelScope::International, TravelScopeResolver::forAirports(['MNL', 'SIN']));
    }

    public function test_an_airport_missing_from_the_curated_list_is_international(): void
    {
        // The old classifier read an unlisted airport as international too, but by
        // accident: it matched a country *name* and anything unmatched fell through.
        $this->assertSame(TravelScope::International, TravelScopeResolver::forAirports(['MNL', 'ZZZ']));
    }

    public function test_an_airport_carrying_no_country_code_is_international(): void
    {
        config(['airports' => [['code' => 'XXX', 'city' => 'Nowhere', 'country' => 'Philippines']]]);

        $this->assertNull(Airports::countryCode('XXX'));
        $this->assertSame(TravelScope::International, TravelScopeResolver::forAirports(['XXX']));
    }

    public function test_legs_are_read_from_both_ends(): void
    {
        $domestic = [
            ['origin' => ['country' => 'PH'], 'destination' => ['country' => 'PH']],
            ['origin' => ['country' => 'PH'], 'destination' => ['country' => 'PH']],
        ];

        $this->assertSame(TravelScope::Domestic, TravelScopeResolver::forLegs($domestic));

        // A domestic first leg connecting onto an international second one.
        $connecting = $domestic;
        $connecting[1]['destination']['country'] = 'SG';

        $this->assertSame(TravelScope::International, TravelScopeResolver::forLegs($connecting));
    }

    public function test_legs_with_no_country_are_international(): void
    {
        $this->assertSame(TravelScope::International, TravelScopeResolver::forLegs([
            ['origin' => ['code' => 'MNL'], 'destination' => ['code' => 'CEB']],
        ]));

        $this->assertSame(TravelScope::International, TravelScopeResolver::forLegs([]));
    }

    public function test_raw_supplier_segments_are_read_from_the_airport_block(): void
    {
        $segment = fn (string $from, string $to): array => [
            'Origin' => ['Airport' => ['CountryCode' => $from]],
            'Destination' => ['Airport' => ['CountryCode' => $to]],
        ];

        $this->assertSame(TravelScope::Domestic, TravelScopeResolver::forSegments([$segment('PH', 'PH')]));
        $this->assertSame(TravelScope::International, TravelScopeResolver::forSegments([$segment('PH', 'SG')]));
        $this->assertSame(TravelScope::International, TravelScopeResolver::forSegments([]));
    }

    public function test_a_hotel_is_classified_by_its_country(): void
    {
        $this->assertSame(TravelScope::Domestic, TravelScopeResolver::forCountryCode('PH'));
        $this->assertSame(TravelScope::International, TravelScopeResolver::forCountryCode('SG'));
    }

    public function test_a_hotel_with_no_catalogue_country_is_international(): void
    {
        $this->assertSame(TravelScope::International, TravelScopeResolver::forCountryCode(null));
        $this->assertSame(TravelScope::International, TravelScopeResolver::forCountryCode(''));
    }

    public function test_every_curated_airport_carries_a_country_code(): void
    {
        // Guards the data rather than the code: an entry added without one silently
        // classifies as international, which is wrong for a Philippine airport.
        foreach (Airports::all() as $airport) {
            $this->assertArrayHasKey('country_code', $airport, "{$airport['code']} has no country_code");
            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $airport['country_code'], $airport['code']);
        }
    }

    public function test_the_curated_list_agrees_with_itself_on_the_philippines(): void
    {
        foreach (Airports::all() as $airport) {
            $this->assertSame(
                $airport['country'] === 'Philippines',
                $airport['country_code'] === 'PH',
                "{$airport['code']}: display country and country_code disagree",
            );
        }
    }
}
