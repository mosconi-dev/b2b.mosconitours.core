<?php

namespace Tests\Feature\TboHotel;

use App\Models\Hotel;
use App\Models\SupplierApiLog;
use App\Services\TboHotel\CancelPolicySet;
use App\Services\TboHotel\DTO\PaxRoom;
use App\Services\TboHotel\DTO\SearchInput;
use App\Services\TboHotel\Exceptions\TboHotelException;
use App\Services\TboHotel\SupplementSet;
use App\Services\TboHotel\TboHotelClient;
use App\Services\TboHotel\TboHotelConfig;
use App\Services\TboHotel\TboHotelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HotelSearchTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api.tbotechnology.in/HotelAPI';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tbohotel.default' => 'test',
            'tbohotel.environments.test.credentials.username' => 'hotel-user',
            'tbohotel.environments.test.credentials.password' => 'hotel-pass',
            'tbohotel.environments.test.base_url' => self::BASE,
            'tbohotel.retry_delay' => 0,
            'tbohotel.search_chunk' => 2,
        ]);

        // The fixture's three hotels, plus one TBO has no availability for.
        foreach (['1022346', '1022350', '1022324', '9999999'] as $i => $code) {
            Hotel::create([
                'source' => 'tbo', 'code' => $code, 'city_code' => '127116',
                'country_code' => 'PH', 'name' => "Hotel {$code}", 'rating' => 3 + ($i % 2),
            ]);
        }
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

    private function input(string $type = 'city', string $code = '127116'): SearchInput
    {
        return new SearchInput(
            checkIn: '2026-09-11',
            checkOut: '2026-09-13',
            rooms: [new PaxRoom(2, 1, [8])],
            guestNationality: 'PH',
            locationType: $type,
            locationCode: $code,
        );
    }

    public function test_a_city_search_chunks_its_hotel_codes(): void
    {
        Http::fake([self::BASE.'/Search' => Http::response($this->fixture('search'))]);

        $this->service()->search($this->input());

        // Four catalogue hotels, two per chunk.
        Http::assertSentCount(2);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();
            $this->assertCount(2, explode(',', $data['HotelCodes']));
            $this->assertSame('2026-09-11', $data['CheckIn']);
            $this->assertSame('PH', $data['GuestNationality']);
            $this->assertSame([['Adults' => 2, 'Children' => 1, 'ChildrenAges' => [8]]], $data['PaxRooms']);
            $this->assertSame(1, $data['Filters']['NoOfRooms']);

            return true;
        });
    }

    /**
     * §18 recommends false; measured, true costs no extra time and is the only way
     * to get the cancel policies and AtProperty supplements it also requires us to
     * show. The measurement wins.
     */
    public function test_it_asks_for_the_detailed_response(): void
    {
        Http::fake([self::BASE.'/Search' => Http::response($this->fixture('search'))]);

        $this->service()->search($this->input());

        Http::assertSent(fn (Request $r): bool => $r->data()['IsDetailedResponse'] === true);
    }

    public function test_searching_one_property_sends_one_code(): void
    {
        Http::fake([self::BASE.'/Search' => Http::response($this->fixture('search'))]);

        $this->service()->search($this->input('hotel', '1022346'));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => $r->data()['HotelCodes'] === '1022346');
    }

    public function test_offers_are_joined_to_the_catalogue_and_sorted_by_price(): void
    {
        Http::fake([self::BASE.'/Search' => Http::response($this->fixture('search'))]);

        $result = $this->service()->search($this->input());

        $this->assertNotEmpty($result->offers);
        $this->assertSame('PHP', $result->currency);

        $fares = array_map(fn ($o): float => $o->lowestFare(), $result->offers);
        $sorted = $fares;
        sort($sorted);
        $this->assertSame($sorted, $fares, 'cheapest first');

        // The name comes from our catalogue: Search returns a code and prices only.
        $this->assertStringStartsWith('Hotel ', $result->offers[0]->name());
        $this->assertNotNull($result->offers[0]->hotel);
    }

    /**
     * A rate we cannot render is worse than one we do not show — Search gives no
     * name, address or photograph, so a hotel missing from the catalogue is dropped.
     */
    public function test_a_hotel_missing_from_the_catalogue_is_dropped(): void
    {
        Hotel::where('code', '1022346')->delete();
        Http::fake([self::BASE.'/Search' => Http::response($this->fixture('search'))]);

        $codes = array_map(fn ($o): string => $o->hotelCode, $this->service()->search($this->input())->offers);

        $this->assertNotContains('1022346', $codes);
    }

    /**
     * A results page that quietly shows nine tenths of a city looks exactly like one
     * that shows all of it.
     */
    public function test_a_failed_chunk_is_reported_rather_than_hidden(): void
    {
        Http::fakeSequence()
            ->push($this->fixture('search'), 200)
            ->push(['Status' => ['Code' => 500, 'Description' => 'Unexpected Error']], 200);

        $result = $this->service()->search($this->input());

        $this->assertTrue($result->isPartial());
        $this->assertSame(1, $result->chunksFailed);
        $this->assertSame(2, $result->chunks);
        $this->assertSame(2, $result->hotelsMissed());
        $this->assertNotEmpty($result->offers, 'what did come back is still shown');
    }

    /**
     * Every chunk failing is not a partial result — it is a failed search, and an
     * empty page that cannot say whether the city is full or the supplier is down
     * is the worst of both.
     */
    public function test_every_chunk_failing_raises_the_supplier_reason(): void
    {
        Http::fake([self::BASE.'/Search' => Http::response(['Status' => ['Code' => 315, 'Description' => 'Session Expired']])]);

        try {
            $this->service()->search($this->input());
            $this->fail('A wholly failed search should have thrown.');
        } catch (TboHotelException $e) {
            $this->assertTrue($e->isExpired());
        }
    }

    /**
     * A chunk of hotels that are simply full is an answer, not an outage.
     */
    public function test_no_availability_is_not_a_failure(): void
    {
        Http::fake([self::BASE.'/Search' => Http::response(['Status' => ['Code' => 201, 'Description' => 'No Available rooms']])]);

        $result = $this->service()->search($this->input());

        $this->assertTrue($result->isEmpty());
        $this->assertFalse($result->isPartial());
        $this->assertSame(0, $result->chunksFailed);
    }

    public function test_a_city_with_no_catalogue_hotels_makes_no_calls(): void
    {
        Http::fake();

        $result = $this->service()->search($this->input('city', '999999'));

        $this->assertTrue($result->isEmpty());
        Http::assertNothingSent();
    }

    public function test_every_chunk_is_logged(): void
    {
        Http::fake([self::BASE.'/Search' => Http::response($this->fixture('search'))]);

        $this->service()->search($this->input());

        $this->assertSame(2, SupplierApiLog::where('type', 'search')->count());
        $this->assertTrue(SupplierApiLog::where('type', 'search')->first()->successful);
    }

    /**
     * TBO sends DD-MM-YYYY. Read as ISO, 11-08-2026 becomes November and every
     * refund window is three months wrong.
     */
    public function test_cancellation_dates_are_read_as_day_first(): void
    {
        $policies = CancelPolicySet::fromResponse([
            ['FromDate' => '11-08-2026 00:00:00', 'ChargeType' => 'Fixed', 'CancellationCharge' => 0],
            ['FromDate' => '12-08-2026 00:00:00', 'ChargeType' => 'Percentage', 'CancellationCharge' => 100],
        ]);

        $this->assertSame('2026-08-11 00:00:00', $policies->forRoom(1)[0]['from']);
    }

    public function test_a_free_window_still_open_is_reported(): void
    {
        $policies = CancelPolicySet::fromResponse([
            ['FromDate' => now()->subDay()->format('d-m-Y H:i:s'), 'ChargeType' => 'Fixed', 'CancellationCharge' => 0],
            ['FromDate' => now()->addDays(20)->format('d-m-Y H:i:s'), 'ChargeType' => 'Percentage', 'CancellationCharge' => 100],
        ]);

        $this->assertSame(now()->addDays(20)->startOfSecond()->toDateTimeString(), $policies->freeUntil());
    }

    /**
     * TBO describes a non-refundable rate as zero charge until today and 100% after,
     * so reading it naively advertises "free cancellation until" a date in the past.
     */
    public function test_a_free_window_that_has_closed_is_not_advertised(): void
    {
        $policies = CancelPolicySet::fromResponse([
            ['FromDate' => now()->subDays(2)->format('d-m-Y H:i:s'), 'ChargeType' => 'Fixed', 'CancellationCharge' => 0],
            ['FromDate' => now()->subDay()->format('d-m-Y H:i:s'), 'ChargeType' => 'Percentage', 'CancellationCharge' => 100],
        ]);

        $this->assertNull($policies->freeUntil());
    }

    public function test_policies_without_an_index_cover_the_whole_booking(): void
    {
        $policies = CancelPolicySet::fromResponse([
            ['FromDate' => '01-09-2026 00:00:00', 'ChargeType' => 'Fixed', 'CancellationCharge' => 0],
        ]);

        $this->assertCount(1, $policies->forRoom(1));
        $this->assertCount(1, $policies->forRoom(2));
    }

    public function test_indexed_policies_belong_to_their_room(): void
    {
        $policies = CancelPolicySet::fromResponse([
            ['Index' => 1, 'FromDate' => '01-09-2026 00:00:00', 'ChargeType' => 'Fixed', 'CancellationCharge' => 0],
            ['Index' => 2, 'FromDate' => '02-09-2026 00:00:00', 'ChargeType' => 'Fixed', 'CancellationCharge' => 500],
        ]);

        $this->assertSame(0.0, $policies->forRoom(1)[0]['charge']);
        $this->assertSame(500.0, $policies->forRoom(2)[0]['charge']);
    }

    /**
     * §18 requires AtProperty charges to be shown before booking — a guest surprised
     * by a deposit at check-in is a complaint we caused.
     */
    public function test_supplements_separate_what_the_guest_pays_at_the_desk(): void
    {
        $supplements = SupplementSet::fromResponse([[
            ['Index' => 1, 'Type' => 'AtProperty', 'Description' => 'Deposit Fee per night ', 'Price' => 500, 'Currency' => 'PHP'],
            ['Index' => 1, 'Type' => 'Included', 'Description' => 'mandatory_tax', 'Price' => 120, 'Currency' => 'PHP'],
        ]]);

        $atProperty = $supplements->payableAtProperty();

        $this->assertCount(1, $atProperty);
        $this->assertSame('Deposit Fee per night', $atProperty[0]['description']);
        $this->assertSame(500.0, $supplements->payableAtPropertyTotal());
    }

    public function test_machine_descriptions_are_made_readable(): void
    {
        $supplements = SupplementSet::fromResponse([
            ['Type' => 'AtProperty', 'Description' => 'mandatory_tax', 'Price' => 20, 'Currency' => 'PHP'],
        ]);

        $this->assertSame('Mandatory tax', $supplements->payableAtProperty()[0]['description']);
    }
}
