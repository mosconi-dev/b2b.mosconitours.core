<?php

namespace Tests\Feature\TboHotel;

use App\Services\TboHotel\CancelPolicySet;
use App\Services\TboHotel\DTO\RoomOffer;
use App\Services\TboHotel\Exceptions\TboHotelException;
use App\Services\TboHotel\TboHotelClient;
use App\Services\TboHotel\TboHotelConfig;
use App\Services\TboHotel\TboHotelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PreBook is the contract. §18 makes its cancellation policy and norms final for the
 * itinerary, so what it returns — not what Search advertised — is what gets charged,
 * stored and refunded against.
 */
class PreBookTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api.tbotechnology.in/HotelAPI';

    private const CODE = '1012705!TB!1!TB!f8cea260-96bf-11f1-a512-aa71e0cecaa6!TB!N!TB!AFF!';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tbohotel.default' => 'test',
            'tbohotel.environments.test.credentials.username' => 'hotel-user',
            'tbohotel.environments.test.credentials.password' => 'hotel-pass',
            'tbohotel.environments.test.base_url' => self::BASE,
            'tbohotel.retry_delay' => 0,
        ]);
    }

    private function service(): TboHotelService
    {
        return new TboHotelService(new TboHotelClient(TboHotelConfig::for('test')));
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tbohotel/{$name}.json")), true);
    }

    public function test_it_posts_the_booking_code_against_the_credit_limit(): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response($this->fixture('prebook'))]);

        $this->service()->preBook(self::CODE);

        Http::assertSent(function (Request $request): bool {
            $this->assertStringEndsWith('/PreBook', $request->url());
            $this->assertSame(self::CODE, $request->data()['BookingCode']);
            // We hold a TBO credit limit; cards are out of scope.
            $this->assertSame('Limit', $request->data()['PaymentMode']);

            return true;
        });
    }

    public function test_it_reads_the_binding_price_and_terms(): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response($this->fixture('prebook'))]);

        $result = $this->service()->preBook(self::CODE);

        $this->assertSame('1012705', $result->hotelCode);
        $this->assertSame(4036.02, $result->totalFare());
        $this->assertSame(898.95, $result->room->totalTax);
        $this->assertTrue($result->room->isRefundable);
        $this->assertTrue($result->isBookable());
    }

    /**
     * The spec says PreBook "adds RateConditions" to the room object. It does not —
     * they hang off the hotel. Reading them from the room yields an empty list and a
     * voucher with no norms on it.
     */
    public function test_rate_conditions_are_read_from_the_hotel_not_the_room(): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response($this->fixture('prebook'))]);

        $result = $this->service()->preBook(self::CODE);

        $this->assertNotEmpty($result->rateConditions);
        $this->assertStringContainsString('cancellation charge', $result->rateConditions[0]);
    }

    /**
     * TBO sends several rate conditions as HTML with the brackets already escaped, so
     * they arrive as literal "&lt;ul&gt;&lt;li&gt;…". Printed as-is an agent reads
     * markup instead of the check-in rules.
     */
    public function test_escaped_markup_in_rate_conditions_is_decoded_and_made_safe(): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response([
            'Status' => ['Code' => 200, 'Description' => 'Successful'],
            'HotelResult' => [[
                'HotelCode' => '1012705',
                'Currency' => 'PHP',
                'RateConditions' => [
                    'CheckIn Instructions: &lt;ul&gt;&lt;li&gt;Photo ID required&lt;/li&gt;&lt;/ul&gt;',
                    'Early check out will attract full cancellation charge',
                    'Hosted: &lt;script&gt;alert(1)&lt;/script&gt;&lt;p&gt;Cash only&lt;/p&gt;',
                ],
                'Rooms' => [['BookingCode' => self::CODE, 'TotalFare' => 100.0, 'Name' => ['Room']]],
            ]],
        ])]);

        $conditions = $this->service()->preBook(self::CODE)->rateConditions;

        $this->assertStringContainsString('<li>Photo ID required</li>', $conditions[0]);
        $this->assertStringNotContainsString('&lt;', $conditions[0]);
        // Plain entries are left exactly as written.
        $this->assertSame('Early check out will attract full cancellation charge', $conditions[1]);
        // Decoding must not become an injection route.
        $this->assertStringNotContainsString('alert', $conditions[2]);
        $this->assertStringContainsString('Cash only', $conditions[2]);
    }

    /**
     * freeUntil() says when the free window shuts and nothing about what the rate costs
     * after — the half that loses money. The schedule is that half, and it has to be
     * flat: cancelPolicies is bucketed by room, so a template iterating it renders one
     * empty row per bucket instead of the policies.
     */
    public function test_the_cancellation_schedule_is_flat_and_dated(): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response($this->fixture('prebook'))]);

        $schedule = $this->service()->preBook(self::CODE)->toArray()['cancellationSchedule'];

        $this->assertNotEmpty($schedule);
        $this->assertSame(['room', 'from', 'chargeType', 'charge'], array_keys($schedule[0]));
        // One room, so no row is labelled with one.
        $this->assertNull($schedule[0]['room']);

        // Oldest first, and every row dated — an undated charge cannot be acted on.
        $dates = array_column($schedule, 'from');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);
        $this->assertNotContains(null, $dates);
    }

    /**
     * Where TBO does distinguish rooms, the rows say which — and only then.
     */
    public function test_a_schedule_names_the_room_only_when_rooms_differ(): void
    {
        $set = CancelPolicySet::fromResponse([
            ['Index' => 1, 'FromDate' => '01-09-2026 00:00:00', 'ChargeType' => 'Fixed', 'CancellationCharge' => 0],
            ['Index' => 2, 'FromDate' => '02-09-2026 00:00:00', 'ChargeType' => 'Percentage', 'CancellationCharge' => 50],
        ]);

        $this->assertSame([1, 2], array_column($set->schedule(), 'room'));
        $this->assertSame(['Fixed', 'Percentage'], array_column($set->schedule(), 'chargeType'));
    }

    /**
     * A weekend rate averaged into a weekday one is a figure the agent would have to
     * defend and could not, so an uneven stay reports no per-night price at all.
     */
    public function test_a_nightly_rate_is_offered_only_when_every_night_costs_the_same(): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response($this->fixture('prebook'))]);

        $room = $this->service()->preBook(self::CODE)->room;

        $this->assertNotNull($room->nightlyRate());
        $this->assertSame($room->dayRates[0], $room->nightlyRate());

        $uneven = RoomOffer::fromResponse([
            'BookingCode' => 'x', 'TotalFare' => 300.0,
            'DayRates' => [[['BasePrice' => 100.0], ['BasePrice' => 200.0]]],
        ]);

        $this->assertNull($uneven->nightlyRate());
    }

    public function test_amenities_come_back_with_the_room(): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response($this->fixture('prebook'))]);

        $this->assertNotEmpty($this->service()->preBook(self::CODE)->amenities);
    }

    /**
     * One BookingCode is one bookable combination: a two-room stay is a single entry
     * carrying both room names and one combined total. Summing entries, or reading
     * only the first name, would misprice or mislabel every multi-room booking.
     */
    public function test_a_multi_room_rate_is_one_code_with_one_combined_total(): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response($this->fixture('prebook-multiroom'))]);

        $result = $this->service()->preBook(self::CODE);

        $this->assertCount(2, $result->room->names);
        $this->assertSame(40203.88, $result->totalFare());
    }

    public function test_a_price_move_is_detected(): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response($this->fixture('prebook'))]);

        $result = $this->service()->preBook(self::CODE);

        $this->assertTrue($result->priceChanged(4000.00));
        $this->assertSame(36.02, $result->priceDelta(4000.00));
    }

    public function test_an_unchanged_price_does_not_trip_the_gate(): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response($this->fixture('prebook'))]);

        $result = $this->service()->preBook(self::CODE);

        $this->assertFalse($result->priceChanged(4036.02));
        $this->assertSame(0.0, $result->priceDelta(4036.02));
    }

    /**
     * Floats that print alike are not reliably equal. A gate that fires on a rounding
     * artefact teaches agents to click through the one that matters.
     */
    public function test_a_rounding_artefact_does_not_trip_the_gate(): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response($this->fixture('prebook'))]);

        $result = $this->service()->preBook(self::CODE);

        $this->assertFalse($result->priceChanged(4036.02 + 0.0000001));
    }

    /**
     * 315 means the BookingCode died with the search behind it — the agent needs a
     * fresh search, not a retry of this one.
     */
    public function test_an_expired_booking_code_is_raised_as_expired(): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response(['Status' => ['Code' => 315, 'Description' => 'Session Expired']])]);

        try {
            $this->service()->preBook(self::CODE);
            $this->fail('An expired BookingCode should have thrown.');
        } catch (TboHotelException $e) {
            $this->assertTrue($e->isExpired());
        }
    }

    public function test_a_rate_that_has_gone_is_raised_as_such(): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response(['Status' => ['Code' => 201, 'Description' => 'No Available rooms']])]);

        $this->expectException(TboHotelException::class);

        $this->service()->preBook(self::CODE);
    }

    /**
     * PreBook commits nothing, so a throttle is safe to retry — unlike Book.
     */
    public function test_a_throttled_prebook_is_retried(): void
    {
        Http::fakeSequence()
            ->push(['Status' => ['Code' => 429, 'Description' => 'Too many requests']], 200)
            ->push($this->fixture('prebook'), 200);

        $this->assertSame(4036.02, $this->service()->preBook(self::CODE)->totalFare());

        Http::assertSentCount(2);
    }

    /**
     * The raw envelope is kept for the booking's audit trail, and must not be shipped
     * to the browser — it is several kilobytes nothing on the page renders.
     */
    public function test_the_browser_payload_excludes_the_raw_envelope(): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response($this->fixture('prebook'))]);

        $result = $this->service()->preBook(self::CODE);

        $this->assertArrayNotHasKey('raw', $result->toArray());
        $this->assertArrayHasKey('Status', $result->raw);
        $this->assertArrayHasKey('freeCancellationUntil', $result->toArray());
        $this->assertArrayHasKey('payableAtProperty', $result->toArray());
    }

    public function test_it_is_logged_against_the_hotel_supplier(): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response($this->fixture('prebook'))]);

        $this->service()->preBook(self::CODE);

        $this->assertDatabaseHas('supplier_api_logs', [
            'supplier' => 'tbohotel',
            'type' => 'prebook',
            'successful' => true,
        ]);
    }
}
