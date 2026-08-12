<?php

namespace Tests\Feature\TboHotel;

use App\Enums\Supplier;
use App\Enums\TboHotelStatus;
use App\Models\SupplierApiLog;
use App\Services\TboHotel\Exceptions\TboHotelException;
use App\Services\TboHotel\TboHotelClient;
use App\Services\TboHotel\TboHotelConfig;
use App\Services\TboHotel\TboHotelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TboHotelClientTest extends TestCase
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
            'tbohotel.retry_delay' => 0, // do not actually sleep in tests
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

    /**
     * TBO's casing is inconsistent and a corrected path is a 404, so the paths are
     * pinned here rather than trusted to look right.
     */
    public function test_method_paths_match_the_specification(): void
    {
        $client = new TboHotelClient(TboHotelConfig::for('test'));

        $expected = [
            'search' => 'Search',
            'prebook' => 'PreBook',
            'book' => 'Book',
            'bookingdetail' => 'BookingDetail',
            'cancel' => 'Cancel',
            'countrylist' => 'CountryList',
            'citylist' => 'CityList',
            'hotelcodelist' => 'hotelcodelist',
            'tbohotelcodelist' => 'TBOHotelCodeList',
            'hoteldetails' => 'HotelDetails',
            'bookingdetailsbydate' => 'BookingDetailsbasedondate',
        ];

        foreach ($expected as $method => $path) {
            $this->assertSame(self::BASE.'/'.$path, $client->url($method), "path for {$method}");
        }
    }

    /**
     * §4 gives a different ceiling per method. One global value would either hold a
     * search open for two minutes or abandon a Book that may already have reserved
     * a room.
     */
    public function test_each_method_carries_its_own_timeout(): void
    {
        $config = TboHotelConfig::for('test');

        $this->assertSame(23, $config['timeouts']['search']);
        $this->assertSame(23, $config['timeouts']['prebook']);
        $this->assertSame(120, $config['timeouts']['book']);
        $this->assertSame(60, $config['timeouts']['default']);
    }

    public function test_the_result_cache_expires_well_inside_the_booking_window(): void
    {
        $this->assertLessThan(
            config('tbohotel.booking_window'),
            config('tbohotel.search_cache_ttl'),
            'a cached price must not outlive the BookingCode that would book it',
        );
    }

    public function test_country_list_is_a_get_with_basic_auth(): void
    {
        Http::fake([self::BASE.'/CountryList' => Http::response($this->fixture('countrylist'))]);

        $countries = $this->service()->countries();

        $this->assertSame(['code' => 'PH', 'name' => 'Philippines'], $countries[1]);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('GET', $request->method());
            $this->assertSame(self::BASE.'/CountryList', $request->url());
            $this->assertSame(
                'Basic '.base64_encode('hotel-user:hotel-pass'),
                $request->header('Authorization')[0] ?? null,
            );

            return true;
        });
    }

    public function test_city_list_is_a_post_carrying_the_country_code(): void
    {
        Http::fake([self::BASE.'/CityList' => Http::response($this->fixture('citylist'))]);

        $cities = $this->service()->cities('ph');

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('POST', $request->method());
            $this->assertSame(['CountryCode' => 'PH'], $request->data());

            return true;
        });

        // The third fixture row has an empty Code: half a record resolves to nothing,
        // so it is dropped rather than stored.
        $this->assertCount(2, $cities);
        $this->assertSame('127343', $cities[0]['code']);
    }

    public function test_every_call_is_logged_against_the_hotel_supplier(): void
    {
        Http::fake([self::BASE.'/CountryList' => Http::response($this->fixture('countrylist'))]);

        $this->service()->countries();

        $log = SupplierApiLog::sole();

        $this->assertSame(Supplier::TboHotel, $log->supplier);
        $this->assertSame('countrylist', $log->type);
        $this->assertSame('test', $log->environment);
        $this->assertSame(self::BASE.'/CountryList', $log->endpoint);
        $this->assertTrue($log->successful);
    }

    /**
     * Basic Auth lives in a header we never log, so the credentials cannot leak
     * through the request body the way TBO Air's password could.
     */
    public function test_credentials_never_reach_the_log_row(): void
    {
        Http::fake([self::BASE.'/CityList' => Http::response($this->fixture('citylist'))]);

        $this->service()->cities('PH');

        $encoded = json_encode(SupplierApiLog::sole()->only(['request', 'response']));

        $this->assertStringNotContainsString('hotel-pass', $encoded);
        $this->assertStringNotContainsString('hotel-user', $encoded);
    }

    public function test_a_refusal_in_the_body_is_an_error_despite_http_200(): void
    {
        Http::fake([self::BASE.'/CountryList' => Http::response($this->fixture('unauthorized'), 200)]);

        try {
            $this->service()->countries();
            $this->fail('An unauthorized status should have thrown.');
        } catch (TboHotelException $e) {
            $this->assertTrue($e->isUnauthorized());
            $this->assertSame(TboHotelStatus::Unauthorized, $e->status());
            // TBO's own words, not ours — they are more specific than anything we
            // could write, and they are what support will ask about.
            $this->assertSame('Access Credentials is incorrect', $e->getMessage());
        }

        $this->assertFalse(SupplierApiLog::sole()->successful);
    }

    public function test_a_throttled_read_is_retried_then_succeeds(): void
    {
        Http::fakeSequence()
            ->push($this->fixture('throttled'), 200)
            ->push($this->fixture('countrylist'), 200);

        $countries = $this->service()->countries();

        $this->assertCount(3, $countries);
        Http::assertSentCount(2);
        // Both attempts are logged: the 429s are the evidence for asking TBO what
        // our QPS limit actually is.
        $this->assertSame(2, SupplierApiLog::count());
    }

    public function test_retries_are_bounded(): void
    {
        config(['tbohotel.retries' => 2]);
        Http::fake([self::BASE.'/CountryList' => Http::response($this->fixture('throttled'), 200)]);

        $this->expectException(TboHotelException::class);

        try {
            $this->service()->countries();
        } finally {
            Http::assertSentCount(3); // the original plus two retries
        }
    }

    public function test_a_transport_failure_is_a_timeout_not_a_status(): void
    {
        Http::fake([self::BASE.'/CountryList' => Http::response('gateway gone', 504)]);

        try {
            $this->service()->countries();
            $this->fail('HTTP 504 should have thrown.');
        } catch (TboHotelException $e) {
            $this->assertTrue($e->isTimeout());
            $this->assertNull($e->status());
            $this->assertSame(504, $e->httpStatus());
        }
    }

    /**
     * hotelcodelist (§13) answers with a bare `{ "HotelCodes": [...] }` and no Status
     * envelope. Absence of a status must not read as absence of success.
     */
    public function test_a_response_without_a_status_envelope_is_not_a_failure(): void
    {
        Http::fake([self::BASE.'/CountryList' => Http::response(['CountryList' => [['Code' => 'PH', 'Name' => 'Philippines']]])]);

        $this->assertCount(1, $this->service()->countries());
        $this->assertTrue(SupplierApiLog::sole()->successful);
    }

    public function test_an_empty_response_is_a_failure(): void
    {
        Http::fake([self::BASE.'/CountryList' => Http::response([], 200)]);

        $this->expectException(TboHotelException::class);

        $this->service()->countries();
    }

    /**
     * An undocumented code must not slip through as "no envelope, therefore fine".
     */
    public function test_an_unknown_status_code_still_fails(): void
    {
        Http::fake([self::BASE.'/CountryList' => Http::response(['Status' => ['Code' => 999, 'Description' => 'Who knows']])]);

        try {
            $this->service()->countries();
            $this->fail('An unknown status should have thrown.');
        } catch (TboHotelException $e) {
            $this->assertSame(999, $e->statusCode());
            $this->assertSame('Who knows', $e->getMessage());
        }
    }
}
