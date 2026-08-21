<?php

namespace Tests\Feature;

use App\Enums\TravelScope;
use App\Models\Hotel;
use App\Services\TboAir\DTO\FareQuote;
use App\Services\TboHotel\DTO\HotelOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 of pricing: the scope is classified once and reaches every surface that will
 * later be priced on it. Nothing here changes a price — the point is to prove the
 * classification is right on real supplier payloads *before* money depends on it.
 */
class TravelScopeSurfacedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/tboair/'.$name), true);
    }

    public function test_a_domestic_fare_quote_is_classified_from_the_supplier_country_codes(): void
    {
        $quote = FareQuote::fromResponse($this->fixture('farequote.json'));

        $this->assertSame(TravelScope::Domestic, $quote->scope);
    }

    public function test_an_international_fare_quote_is_classified_from_the_supplier_country_codes(): void
    {
        $quote = FareQuote::fromResponse($this->fixture('farequote-international.json'));

        $this->assertSame(TravelScope::International, $quote->scope);
    }

    public function test_the_quote_snapshot_carries_scope_without_dropping_is_domestic(): void
    {
        // toArray() is persisted to bookings.quote, and ETicket reads `isDomestic` off
        // that snapshot to label a passenger's document. Dropping the key would blank
        // the label on every booking already taken.
        $snapshot = FareQuote::fromResponse($this->fixture('farequote.json'))->toArray();

        $this->assertSame('domestic', $snapshot['scope']);
        $this->assertTrue($snapshot['isDomestic']);

        $international = FareQuote::fromResponse($this->fixture('farequote-international.json'))->toArray();

        $this->assertSame('international', $international['scope']);
        $this->assertFalse($international['isDomestic']);
    }

    public function test_a_hotel_offer_is_classified_from_its_catalogue_country(): void
    {
        $local = Hotel::create([
            'source' => 'tbo', 'code' => '1022346', 'city_code' => '127116',
            'country_code' => 'PH', 'name' => 'Manila Hotel', 'rating' => 4,
        ]);

        $offer = HotelOffer::fromResponse(['HotelCode' => '1022346', 'Currency' => 'PHP', 'Rooms' => []], $local);

        $this->assertSame(TravelScope::Domestic, $offer->scope());

        $abroad = Hotel::create([
            'source' => 'tbo', 'code' => '2000001', 'city_code' => '100000',
            'country_code' => 'SG', 'name' => 'Singapore Hotel', 'rating' => 5,
        ]);

        $this->assertSame(
            TravelScope::International,
            HotelOffer::fromResponse(['HotelCode' => '2000001', 'Currency' => 'PHP', 'Rooms' => []], $abroad)->scope(),
        );
    }

    public function test_a_hotel_offer_with_no_catalogue_row_is_international(): void
    {
        $offer = HotelOffer::fromResponse(['HotelCode' => '9999999', 'Currency' => 'PHP', 'Rooms' => []]);

        $this->assertSame(TravelScope::International, $offer->scope());
    }

    public function test_the_hotel_card_payload_carries_scope_and_location(): void
    {
        $hotel = Hotel::create([
            'source' => 'tbo', 'code' => '1022346', 'city_code' => '127116',
            'country_code' => 'PH', 'name' => 'Manila Hotel', 'rating' => 4,
        ]);

        $payload = HotelOffer::fromResponse(
            ['HotelCode' => '1022346', 'Currency' => 'PHP', 'Rooms' => []],
            $hotel,
        )->toArray();

        $this->assertSame('domestic', $payload['scope']);
        $this->assertSame('Domestic', $payload['scopeLabel']);
        $this->assertSame('PH', $payload['countryCode']);
        $this->assertSame('127116', $payload['cityCode']);
    }
}
