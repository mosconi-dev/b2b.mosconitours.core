<?php

namespace Tests\Feature\TboHotel;

use App\Models\Hotel;
use App\Models\HotelCity;
use App\Models\HotelCountry;
use App\Models\HotelSyncRun;
use App\Services\TboHotel\CatalogueSyncService;
use App\Services\TboHotel\TboHotelClient;
use App\Services\TboHotel\TboHotelConfig;
use App\Services\TboHotel\TboHotelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogueSyncTest extends TestCase
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
        ]);
    }

    private function sync(): CatalogueSyncService
    {
        return new CatalogueSyncService(
            new TboHotelService(new TboHotelClient(TboHotelConfig::for('test')))
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tbohotel/{$name}.json")), true);
    }

    public function test_it_stores_countries_and_cities(): void
    {
        Http::fake([
            self::BASE.'/CountryList' => Http::response($this->fixture('countrylist')),
            self::BASE.'/CityList' => Http::response($this->fixture('citylist')),
        ]);

        $this->assertSame(3, $this->sync()->syncCountries()->processed);
        $this->assertSame(4, $this->sync()->syncCities('ph')->processed);

        $this->assertDatabaseHas('hotel_countries', ['code' => 'PH', 'name' => 'Philippines']);
        $this->assertDatabaseHas('hotel_cities', ['code' => '127116', 'name' => 'Manila', 'country_code' => 'PH']);
    }

    /**
     * The live system skips any row whose code it already holds, so a city TBO
     * renames keeps its old name for ever. A re-sync must update.
     */
    public function test_a_renamed_row_is_updated_not_skipped(): void
    {
        HotelCity::create([
            'source' => 'tbo', 'code' => '127116', 'country_code' => 'PH', 'name' => 'Manila (old)',
        ]);

        Http::fake([self::BASE.'/CityList' => Http::response($this->fixture('citylist'))]);

        $this->sync()->syncCities('PH');

        $this->assertSame('Manila', HotelCity::where('code', '127116')->value('name'));
        $this->assertSame(4, HotelCity::count(), 'the existing row must be updated, not duplicated');
    }

    /**
     * Re-syncing the city list must not undo the admin's choices about which cities
     * we carry — that would silently empty the catalogue on the next hotel pull.
     */
    public function test_re_syncing_cities_preserves_which_are_enabled(): void
    {
        HotelCity::create([
            'source' => 'tbo', 'code' => '127116', 'country_code' => 'PH',
            'name' => 'Manila', 'is_enabled' => true,
        ]);

        Http::fake([self::BASE.'/CityList' => Http::response($this->fixture('citylist'))]);

        $this->sync()->syncCities('PH');

        $this->assertTrue(HotelCity::where('code', '127116')->value('is_enabled'));
    }

    public function test_hotels_are_stored_under_the_city_we_asked_for(): void
    {
        Http::fake([self::BASE.'/TBOHotelCodeList' => Http::response($this->fixture('tbohotelcodelist'))]);

        $run = $this->sync()->syncHotels('100834');

        $this->assertSame(16, $run->processed);

        $hotel = Hotel::where('code', '1015014')->sole();

        // TBO answers Alcoy's hotels with CityName "Cebu City". Search is driven off
        // the code we asked with, so that is what has to be stored.
        $this->assertSame('100834', $hotel->city_code);
        $this->assertSame('Dive Point Alcoy Resort', $hotel->name);
    }

    /**
     * Three normalisations the real payloads forced: "ThreeStar" is an integer,
     * "ph" is a country code, and the coordinates are strings.
     */
    public function test_it_normalises_ratings_country_codes_and_coordinates(): void
    {
        Http::fake([self::BASE.'/TBOHotelCodeList' => Http::response($this->fixture('tbohotelcodelist'))]);

        $this->sync()->syncHotels('100834');

        $hotel = Hotel::where('code', '1015014')->sole();

        $this->assertSame(3, $hotel->rating);
        $this->assertSame('PH', $hotel->country_code);
        $this->assertSame(9.68621, $hotel->latitude);
        $this->assertSame(123.50438, $hotel->longitude);
    }

    public function test_a_city_sync_records_its_count_and_timestamp(): void
    {
        HotelCity::create(['source' => 'tbo', 'code' => '100834', 'country_code' => 'PH', 'name' => 'Alcoy']);
        Http::fake([self::BASE.'/TBOHotelCodeList' => Http::response($this->fixture('tbohotelcodelist'))]);

        $this->sync()->syncHotels('100834');

        $city = HotelCity::where('code', '100834')->sole();

        $this->assertSame(16, $city->hotels_count);
        $this->assertNotNull($city->hotels_synced_at);
    }

    /**
     * The defect that makes production's sync unusable: it returns a 422 on the
     * first failed city, losing every city after it with no record of where it got
     * to. One unreachable city must cost one city.
     */
    public function test_one_failing_city_does_not_abort_the_run(): void
    {
        foreach ([['100834', 'Alcoy'], ['114570', 'Cebu City'], ['127116', 'Manila']] as [$code, $name]) {
            HotelCity::create([
                'source' => 'tbo', 'code' => $code, 'country_code' => 'PH',
                'name' => $name, 'is_enabled' => true,
            ]);
        }

        Http::fake([self::BASE.'/TBOHotelCodeList' => function (Request $request) {
            return $request->data()['CityCode'] === '114570'
                ? Http::response(['Status' => ['Code' => 500, 'Description' => 'Unexpected Error']])
                : Http::response($this->fixture('tbohotelcodelist'));
        }]);

        $run = $this->sync()->syncEnabledCities();

        $this->assertSame(HotelSyncRun::COMPLETED, $run->status);
        $this->assertSame(2, $run->processed);
        $this->assertSame(1, $run->failed);
        $this->assertSame('114570', $run->failures[0]['target']);
        $this->assertSame('Cebu City', $run->failures[0]['label']);
        $this->assertStringContainsString('Unexpected Error', $run->failures[0]['reason']);
    }

    public function test_only_enabled_cities_are_pulled(): void
    {
        HotelCity::create(['source' => 'tbo', 'code' => '100834', 'country_code' => 'PH', 'name' => 'Alcoy', 'is_enabled' => true]);
        HotelCity::create(['source' => 'tbo', 'code' => '127116', 'country_code' => 'PH', 'name' => 'Manila', 'is_enabled' => false]);

        Http::fake([self::BASE.'/TBOHotelCodeList' => Http::response($this->fixture('tbohotelcodelist'))]);

        $this->assertSame(1, $this->sync()->syncEnabledCities()->processed);
        Http::assertSentCount(1);
    }

    public function test_details_enrich_hotels_and_batch_their_codes(): void
    {
        Http::fake([
            self::BASE.'/TBOHotelCodeList' => Http::response($this->fixture('tbohotelcodelist')),
            self::BASE.'/HotelDetails' => Http::response($this->fixture('hoteldetails')),
        ]);

        $this->sync()->syncHotels('100834');
        $this->sync()->syncDetails('100834');

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'HotelDetails')) {
                return false;
            }

            // One call for all sixteen, not sixteen calls.
            $this->assertCount(16, explode(',', $request->data()['Hotelcodes']));
            $this->assertSame('EN', $request->data()['Language']);

            return true;
        });

        $detailed = Hotel::whereNotNull('description')->first();

        $this->assertNotNull($detailed);
        $this->assertNotEmpty($detailed->images);
        $this->assertNotEmpty($detailed->facilities);
        // HotelDetails gives one "lat|lng" string where the code list gives two
        // fields; both have to land on the same columns.
        $this->assertNotNull($detailed->latitude);
    }

    /**
     * TBO simply omits a hotel it does not recognise. If those are left unstamped
     * the enrichment queue never empties and every run asks for them again.
     */
    public function test_hotels_tbo_does_not_return_are_still_marked_as_attempted(): void
    {
        Http::fake([
            self::BASE.'/TBOHotelCodeList' => Http::response($this->fixture('tbohotelcodelist')),
            // A response covering none of the requested codes.
            self::BASE.'/HotelDetails' => Http::response(['Status' => ['Code' => 200], 'HotelDetails' => []]),
        ]);

        $this->sync()->syncHotels('100834');
        $run = $this->sync()->syncDetails('100834');

        $this->assertSame(16, $run->processed);
        $this->assertSame(0, Hotel::needingDetail()->count());
        $this->assertNull(Hotel::first()->description);
    }

    public function test_a_failed_detail_batch_is_recorded_rather_than_thrown(): void
    {
        Http::fake([
            self::BASE.'/TBOHotelCodeList' => Http::response($this->fixture('tbohotelcodelist')),
            self::BASE.'/HotelDetails' => Http::response(['Status' => ['Code' => 429, 'Description' => 'QPS Exceeded']]),
        ]);

        $this->sync()->syncHotels('100834');
        $run = $this->sync()->syncDetails('100834');

        $this->assertSame(HotelSyncRun::COMPLETED, $run->status);
        $this->assertSame(1, $run->failed);
        // Nothing was stamped, so a later run picks the whole batch up again.
        $this->assertSame(16, Hotel::needingDetail()->count());
    }

    public function test_every_sync_leaves_a_run_record(): void
    {
        Http::fake([self::BASE.'/CountryList' => Http::response($this->fixture('countrylist'))]);

        $run = $this->sync()->syncCountries();

        $this->assertSame('countries', $run->scope);
        $this->assertSame(HotelSyncRun::COMPLETED, $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(1, HotelSyncRun::count());
    }

    public function test_a_total_failure_is_recorded_on_the_run(): void
    {
        Http::fake([self::BASE.'/CountryList' => Http::response($this->fixture('unauthorized'))]);

        $run = $this->sync()->syncCountries();

        $this->assertSame(HotelSyncRun::FAILED, $run->status);
        $this->assertStringContainsString('Access Credentials is incorrect', $run->message);
        $this->assertSame(0, HotelCountry::count());
    }
}
