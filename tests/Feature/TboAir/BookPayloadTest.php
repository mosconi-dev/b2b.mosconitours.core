<?php

namespace Tests\Feature\TboAir;

use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\TboAir\TboBookPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Book/Ticket request body. Pure assembly — nothing here touches the network.
 */
class BookPayloadTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tboair/{$name}")), true);
    }

    private function booking(array $overrides = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'trace_id' => 'trace-abc-123',
            'result_index' => str_repeat('R', 40),
            'is_lcc' => true,
            'result_type' => 1,
            'quote_raw' => $this->fixture('farequote.json'),
            'seats_available' => [9],
            'pax' => [[
                'type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz',
                'gender' => 'M', 'dateOfBirth' => '1990-04-05', 'nationality' => 'PH',
                'isLeadPax' => true,
                'email' => 'agent@example.com', 'mobile' => '09170000000', 'mobileCountryCode' => '63',
                'addressLine1' => '123 Rizal Street', 'addressLine2' => null,
                'city' => 'Makati', 'countryCode' => 'PH', 'countryName' => 'Philippines',
                'ssr' => ['baggage' => null, 'meal' => null],
            ]],
        ], $overrides));
    }

    private function build(Booking $booking, ?string $pnr = null): array
    {
        return TboBookPayload::for($booking, 'token-abc', '203.0.113.9', 'Mozilla/5.0', $pnr);
    }

    public function test_it_sends_the_result_index_as_result_id(): void
    {
        $booking = $this->booking();

        // TBO calls it ResultId on Book; it is our ResultIndex.
        $this->assertSame($booking->result_index, $this->build($booking)['ResultId']);
    }

    public function test_it_sends_the_trace_id_as_tracking_id(): void
    {
        $payload = $this->build($this->booking());

        $this->assertSame('trace-abc-123', $payload['TrackingId']);
        $this->assertSame('trace-abc-123', $payload['Itinerary']['TrackingId']);
    }

    public function test_it_carries_the_token_and_ip_at_both_levels(): void
    {
        $payload = $this->build($this->booking());

        $this->assertSame('token-abc', $payload['TokenId']);
        $this->assertSame('token-abc', $payload['Itinerary']['TokenId']);
        $this->assertSame('203.0.113.9', $payload['IPAddress']);
        $this->assertSame('203.0.113.9', $payload['Itinerary']['ClientIP']);
    }

    // ---- Segments ---------------------------------------------------------

    public function test_it_sends_the_quoted_segments_verbatim(): void
    {
        $segments = $this->build($this->booking())['Itinerary']['Segments_BE'];
        $quoted = $this->fixture('farequote.json')['Response']['Results']['Segments'];
        $expected = is_array($quoted[0]) && array_is_list($quoted[0]) ? $quoted[0] : $quoted;

        $this->assertCount(count($expected), $segments);
        // Everything TBO sent survives; only NoOfSeatAvailable is added back.
        $this->assertSame($expected[0]['Airline'], $segments[0]['Airline']);
        $this->assertSame($expected[0]['Origin'], $segments[0]['Origin']);
    }

    public function test_it_restores_seat_availability_onto_the_segments(): void
    {
        $segments = $this->build($this->booking(['seats_available' => [7]]))['Itinerary']['Segments_BE'];

        $this->assertSame(7, $segments[0]['NoOfSeatAvailable']);
    }

    /**
     * Claiming zero seats on a flight whose availability was never captured would be a
     * lie about the one field that can stop a sale, so the key is omitted instead.
     */
    public function test_an_uncaptured_seat_count_is_omitted_not_zeroed(): void
    {
        $segments = $this->build($this->booking(['seats_available' => []]))['Itinerary']['Segments_BE'];

        $this->assertArrayNotHasKey('NoOfSeatAvailable', $segments[0]);
    }

    public function test_it_sends_the_search_result_type(): void
    {
        $this->assertSame(1, $this->build($this->booking())['Itinerary']['ResultType']);
        $this->assertNull($this->build($this->booking(['result_type' => null]))['Itinerary']['ResultType']);
    }

    // ---- Passengers -------------------------------------------------------

    public function test_it_encodes_passenger_strings_as_tbo_enums(): void
    {
        $pax = $this->build($this->booking())['Itinerary']['Passenger'][0];

        $this->assertSame('Mr', $pax['Title']); // the word — TBO refuses the ordinal
        $this->assertSame(1, $pax['Type']);    // Adult
        $this->assertSame(1, $pax['Gender']);  // Male
        $this->assertTrue($pax['IsLeadPax']);
    }

    public function test_it_puts_the_fanned_out_contact_block_on_the_passenger(): void
    {
        $pax = $this->build($this->booking())['Itinerary']['Passenger'][0];

        $this->assertSame('123 Rizal Street', $pax['AddressLine1']);
        $this->assertSame('agent@example.com', $pax['Email']);
        $this->assertSame('63', $pax['Mobile1CountryCode']);
        $this->assertSame(['CountryCode' => 'PH', 'CountryName' => 'Philippines'], $pax['Country']);
        $this->assertSame('Makati', $pax['City']['CityName']);
    }

    public function test_it_sends_the_frequent_flyer_keys_as_null(): void
    {
        $pax = $this->build($this->booking())['Itinerary']['Passenger'][0];

        $this->assertArrayHasKey('FFAirline', $pax);
        $this->assertArrayHasKey('FFNumber', $pax);
        $this->assertNull($pax['FFAirline']);
        $this->assertNull($pax['FFNumber']);
    }

    public function test_it_formats_dates_the_way_tbo_expects(): void
    {
        $pax = $this->build($this->booking())['Itinerary']['Passenger'][0];

        $this->assertSame('1990-04-05T00:00:00', $pax['DateOfBirth']);
    }

    /**
     * TBO is explicit that these arrays must never be null.
     */
    public function test_ssr_arrays_are_empty_never_null(): void
    {
        $pax = $this->build($this->booking())['Itinerary']['Passenger'][0];

        $this->assertSame([], $pax['Baggage']);
        $this->assertSame([], $pax['MealDynamic']);
        $this->assertSame([], $pax['SeatDynamic']);
    }

    public function test_it_sends_a_selected_ancillary(): void
    {
        $booking = $this->booking();
        $pax = $booking->pax;
        $pax[0]['ssr'] = ['baggage' => ['code' => 'PBAG20', 'description' => '20 KG'], 'meal' => null];
        $booking->update(['pax' => $pax]);

        $sent = $this->build($booking->fresh())['Itinerary']['Passenger'][0];

        $this->assertSame([['Code' => 'PBAG20', 'Description' => '20 KG']], $sent['Baggage']);
    }

    public function test_an_infant_never_carries_baggage_or_a_seat(): void
    {
        $booking = $this->booking();
        $pax = $booking->pax;
        $pax[] = [
            'type' => 'Infant', 'title' => 'Mr', 'firstName' => 'Baby', 'lastName' => 'Cruz',
            'gender' => 'M', 'isLeadPax' => false,
            'ssr' => ['baggage' => ['code' => 'PBAG20', 'description' => '20 KG', 'dateOfBirth' => '2025-06-01'], 'meal' => null],
        ];
        $booking->update(['pax' => $pax]);

        $infant = $this->build($booking->fresh())['Itinerary']['Passenger'][1];

        $this->assertSame(3, $infant['Type']);
        $this->assertSame([], $infant['Baggage'], 'infants may not carry extra baggage');
        $this->assertSame([], $infant['SeatDynamic']);
    }

    /**
     * The live system sends the whole itinerary Fare object on each passenger rather
     * than the per-pax split TBO documents, and tickets successfully.
     */
    public function test_each_passenger_carries_the_quoted_fare_object(): void
    {
        $pax = $this->build($this->booking())['Itinerary']['Passenger'][0];
        $fare = $this->fixture('farequote.json')['Response']['Results']['Fare'];

        $this->assertSame($fare, $pax['Fare_BE']);
    }

    // ---- Identity documents -----------------------------------------------

    /**
     * The document follows the ROUTE, not TBO's flags. Most people flying Manila to
     * Cebu hold no passport, so a domestic itinerary asks for a government ID and
     * sends IdType 2.
     */
    public function test_a_domestic_itinerary_sends_a_government_id(): void
    {
        $booking = $this->booking();
        $pax = $booking->pax;
        $pax[0] += ['documentNumber' => 'UMID-1234-5678', 'documentExpiry' => '2032-05-01'];
        $booking->update(['pax' => $pax]);

        $sent = $this->build($booking->fresh())['Itinerary']['Passenger'][0];

        $this->assertSame(2, $sent['PassengerIdType']);
        $this->assertSame('UMID-1234-5678', $sent['PassengerIdNo']);
        $this->assertSame(2, $sent['IdDetails'][0]['IdType']);
        $this->assertSame('UMID-1234-5678', $sent['IdDetails'][0]['IdNumber']);
        $this->assertSame(0, $sent['IdDetails'][0]['PaxId']);
    }

    /**
     * The case that refused a real booking: a domestic PR fare that flags passport
     * required. Rather than demand a passport nobody has, the government ID goes into
     * the passport fields — truncated to the 15 characters TBO accepts.
     */
    public function test_a_domestic_fare_that_demands_a_passport_gets_the_id_instead(): void
    {
        $quote = $this->fixture('farequote.json');
        $quote['Response']['Results']['IsPassportRequiredAtTicket'] = true;

        $booking = $this->booking(['quote_raw' => $quote]);
        $pax = $booking->pax;
        // A real Philippine ID: hyphenated, and longer than the field allows.
        $pax[0] += ['documentNumber' => 'UMID-1234-5678901-XYZ', 'documentExpiry' => '2032-05-01'];
        $booking->update(['pax' => $pax]);

        $sent = $this->build($booking->fresh())['Itinerary']['Passenger'][0];

        // TBO refuses a Book with "Passport number must contain only letters and
        // numbers", so the separators go before the truncation — otherwise the 15
        // characters that survive are mostly punctuation.
        $this->assertSame('UMID12345678901', $sent['PassportNo']);
        $this->assertSame('UMID-1234-5678901-XYZ', $sent['PassengerIdNo'], 'the ID itself is untouched');
        $this->assertSame('2032-05-01T00:00:00', $sent['PassportExpiry']);
        $this->assertSame(2, $sent['PassengerIdType'], 'still a domestic ID');
    }

    public function test_a_domestic_fare_that_does_not_ask_sends_no_passport_at_all(): void
    {
        $booking = $this->booking();
        $pax = $booking->pax;
        $pax[0] += ['documentNumber' => 'UMID-1234', 'documentExpiry' => '2032-05-01'];
        $booking->update(['pax' => $pax]);

        $sent = $this->build($booking->fresh())['Itinerary']['Passenger'][0];

        $this->assertNull($sent['PassportNo']);
        $this->assertNull($sent['PassportExpiry']);
    }

    public function test_an_international_itinerary_sends_the_passport(): void
    {
        $booking = $this->booking(['quote_raw' => $this->fixture('farequote-international.json')]);
        $pax = $booking->pax;
        $pax[0] += [
            'documentNumber' => 'P1234567A', 'documentExpiry' => '2032-05-01',
            'documentIssueCountry' => 'PH', 'documentIssueDate' => '2022-05-01',
        ];
        $booking->update(['pax' => $pax]);

        $sent = $this->build($booking->fresh())['Itinerary']['Passenger'][0];

        $this->assertSame(1, $sent['PassengerIdType']);
        $this->assertSame('P1234567A', $sent['PassportNo'], 'not truncated');
        $this->assertSame('PH', $sent['PassportIssueCountryCode']);
        $this->assertSame('2022-05-01T00:00:00', $sent['PassportIssueDate']);
        $this->assertSame(1, $sent['IdDetails'][0]['IdType']);
    }

    /**
     * A driver's licence or UMID often has no expiry to hand, but TBO still wants one.
     */
    public function test_a_domestic_id_without_an_expiry_gets_a_far_future_one(): void
    {
        $booking = $this->booking();
        $pax = $booking->pax;
        $pax[0] += ['documentNumber' => 'UMID-1234'];
        $booking->update(['pax' => $pax]);

        $sent = $this->build($booking->fresh())['Itinerary']['Passenger'][0];

        $this->assertSame(now()->addYears(20)->format('Y-m-d').'T00:00:00', $sent['PassengerIdExpiry']);
    }

    public function test_a_passenger_with_no_document_sends_an_empty_id_block(): void
    {
        $sent = $this->build($this->booking())['Itinerary']['Passenger'][0];

        $this->assertSame([], $sent['IdDetails']);
        $this->assertNull($sent['PassengerIdNo']);
    }

    // ---- Ticket vs Book ---------------------------------------------------

    /**
     * Ticket is this payload with a PNR — one builder, both calls.
     */
    public function test_book_sends_an_empty_pnr(): void
    {
        $payload = $this->build($this->booking());

        $this->assertSame('', $payload['PNR']);
        $this->assertSame('', $payload['Itinerary']['PNR']);
    }

    public function test_ticketing_a_held_pnr_sets_it_at_both_levels(): void
    {
        $payload = $this->build($this->booking(), 'QWER12');

        $this->assertSame('QWER12', $payload['PNR']);
        $this->assertSame('QWER12', $payload['Itinerary']['PNR']);
    }

    // ---- Derived fields ---------------------------------------------------

    public function test_it_derives_route_and_point_of_sale_from_the_segments(): void
    {
        $payload = $this->build($this->booking());

        $this->assertSame('MNL', $payload['Itinerary']['Origin']);
        $this->assertSame('PH', $payload['PointOfSale']);
        $this->assertSame('Philippines', $payload['RequestOrigin']);
    }

    public function test_it_echoes_the_lead_passenger_as_user_data(): void
    {
        $this->assertSame('Juan Cruz', $this->build($this->booking())['UserData']);
    }

    public function test_it_marks_a_one_way_journey(): void
    {
        $this->assertSame(1, $this->build($this->booking())['Itinerary']['SearchType']);
    }

    // ---- Refusals ---------------------------------------------------------

    /**
     * Every field above comes from the stored quote, so a booking without one must
     * fail loudly rather than send TBO a hollow itinerary.
     */
    public function test_it_refuses_a_booking_with_no_stored_quote(): void
    {
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('no stored fare quote');

        $this->build($this->booking(['quote_raw' => null]));
    }

    public function test_it_refuses_a_booking_with_no_passengers(): void
    {
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('no passengers');

        $this->build($this->booking(['pax' => []]));
    }
}
