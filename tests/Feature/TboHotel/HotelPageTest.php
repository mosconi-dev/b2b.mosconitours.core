<?php

namespace Tests\Feature\TboHotel;

use App\Models\Hotel;
use App\Models\HotelCity;
use App\Models\HotelCountry;
use App\Services\TboHotel\DTO\PaxRoom;
use App\Services\TboHotel\DTO\SearchInput;
use App\Services\TboHotel\HotelSearchCache;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class HotelPageTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    private const BASE = 'https://api.tbotechnology.in/HotelAPI';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        config([
            'tbohotel.default' => 'test',
            'tbohotel.environments.test.credentials.username' => 'hotel-user',
            'tbohotel.environments.test.credentials.password' => 'hotel-pass',
            'tbohotel.environments.test.base_url' => self::BASE,
            'tbohotel.retry_delay' => 0,
        ]);

        HotelCountry::create(['source' => 'tbo', 'code' => 'PH', 'name' => 'Philippines']);
        HotelCity::create(['source' => 'tbo', 'code' => '127116', 'country_code' => 'PH', 'name' => 'Manila', 'is_enabled' => true]);

        foreach (['1022346', '1022350', '1022324'] as $code) {
            Hotel::create([
                'source' => 'tbo', 'code' => $code, 'city_code' => '127116',
                'country_code' => 'PH', 'name' => "Hotel {$code}", 'rating' => 3,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tbohotel/{$name}.json")), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'checkIn' => now()->addDays(30)->toDateString(),
            'checkOut' => now()->addDays(32)->toDateString(),
            'locationType' => 'city',
            'locationCode' => '127116',
            'guestNationality' => 'PH',
            'rooms' => [['adults' => 2, 'children' => 0, 'childrenAges' => []]],
        ], $overrides);
    }

    public function test_the_page_renders_for_a_permitted_user(): void
    {
        $this->actingAs($this->userWith(['hotel.view']))
            ->get('/hotels')
            ->assertOk()
            ->assertSee('Search a Hotel')
            ->assertSee('Lead guest nationality');
    }

    public function test_the_page_is_gated(): void
    {
        $this->actingAs($this->userWith(['flight.view']))->get('/hotels')->assertForbidden();
    }

    public function test_searching_returns_offers(): void
    {
        Http::fake([self::BASE.'/Search' => Http::response($this->fixture('search'))]);

        $this->actingAs($this->userWith(['hotel.view', 'hotel.search']))
            ->postJson('/hotels/search', $this->payload())
            ->assertOk()
            ->assertJsonPath('currency', 'PHP')
            ->assertJsonPath('nights', 2)
            ->assertJsonPath('rooms', 1)
            ->assertJsonPath('guests', 2)
            ->assertJsonCount(3, 'offers');
    }

    public function test_searching_requires_the_search_permission(): void
    {
        $this->actingAs($this->userWith(['hotel.view']))
            ->postJson('/hotels/search', $this->payload())
            ->assertForbidden();
    }

    /**
     * §18 is explicit that a wrong guest nationality is an operational and financial
     * problem TBO will not carry, so it is never defaulted for the agent.
     */
    public function test_nationality_is_required(): void
    {
        $this->actingAs($this->userWith(['hotel.view', 'hotel.search']))
            ->postJson('/hotels/search', $this->payload(['guestNationality' => '']))
            ->assertJsonValidationErrors('guestNationality');
    }

    public function test_a_child_without_an_age_is_refused_before_tbo_sees_it(): void
    {
        $this->actingAs($this->userWith(['hotel.view', 'hotel.search']))
            ->postJson('/hotels/search', $this->payload([
                'rooms' => [['adults' => 2, 'children' => 2, 'childrenAges' => [8]]],
            ]))
            ->assertJsonValidationErrors('rooms.0.childrenAges');
    }

    public function test_a_past_check_in_is_refused(): void
    {
        $this->actingAs($this->userWith(['hotel.view', 'hotel.search']))
            ->postJson('/hotels/search', $this->payload(['checkIn' => now()->subDay()->toDateString()]))
            ->assertJsonValidationErrors('checkIn');
    }

    public function test_an_over_long_stay_is_refused(): void
    {
        $this->actingAs($this->userWith(['hotel.view', 'hotel.search']))
            ->postJson('/hotels/search', $this->payload([
                'checkOut' => now()->addDays(70)->toDateString(),
            ]))
            ->assertJsonValidationErrors('checkOut');
    }

    /**
     * The distinctions matter to the agent: an expired search needs re-running, a
     * throttle needs a moment, and neither should read as the other.
     */
    public function test_an_expired_booking_code_asks_for_a_fresh_search(): void
    {
        Http::fake([self::BASE.'/Search' => Http::response(['Status' => ['Code' => 315, 'Description' => 'Session Expired']])]);

        $this->actingAs($this->userWith(['hotel.view', 'hotel.search']))
            ->postJson('/hotels/search', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('message', 'These prices have expired. Search again to see current availability.');
    }

    public function test_a_supplier_timeout_is_reported_as_one(): void
    {
        Http::fake([self::BASE.'/Search' => Http::response('', 504)]);

        $this->actingAs($this->userWith(['hotel.view', 'hotel.search']))
            ->postJson('/hotels/search', $this->payload())
            ->assertStatus(504);
    }

    /**
     * Most of the catalogue has never been enriched, so the panel fetches detail the
     * first time anyone opens a property rather than making us crawl every hotel.
     */
    public function test_opening_a_property_enriches_it_once(): void
    {
        Http::fake([self::BASE.'/HotelDetails' => Http::response($this->fixture('hoteldetails'))]);

        $hotel = Hotel::create([
            'source' => 'tbo', 'code' => '1015014', 'city_code' => '127116',
            'country_code' => 'PH', 'name' => 'Dive Point', 'rating' => 3,
        ]);

        $this->assertNull($hotel->detailed_at);

        $this->actingAs($this->userWith(['hotel.view']))
            ->getJson('/hotels/1015014')
            ->assertOk()
            ->assertJsonPath('code', '1015014')
            ->assertJsonStructure(['description', 'facilities', 'images']);

        $this->assertNotNull($hotel->fresh()->detailed_at);

        // Second open costs nothing.
        $this->actingAs($this->userWith(['hotel.view']))->getJson('/hotels/1015014')->assertOk();
        Http::assertSentCount(1);
    }

    /**
     * A missing description is not a reason to fail the panel — the rates are what
     * the agent came for.
     */
    public function test_the_panel_survives_a_failed_enrichment(): void
    {
        Http::fake([self::BASE.'/HotelDetails' => Http::response(['Status' => ['Code' => 500, 'Description' => 'Unexpected Error']])]);

        $this->actingAs($this->userWith(['hotel.view']))
            ->getJson('/hotels/1022346')
            ->assertOk()
            ->assertJsonPath('name', 'Hotel 1022346');
    }

    /**
     * A repeated search must come out of the cache intact.
     *
     * The default test store neither serializes nor enforces `serializable_classes`,
     * so it will happily hand back an object the real one refuses. This configures a
     * store that behaves like production: values are serialized, and no class is
     * allowed back out. Caching a SearchResult under those rules returns
     * __PHP_Incomplete_Class and the second search dies on the first method call.
     */
    public function test_a_repeated_search_is_served_from_cache_intact(): void
    {
        config([
            'cache.serializable_classes' => false,
            'cache.stores.array.serialize' => true,
        ]);
        Cache::purge('array');

        Http::fake([self::BASE.'/Search' => Http::response($this->fixture('search'))]);

        $user = $this->userWith(['hotel.view', 'hotel.search']);
        $payload = $this->payload();

        $first = $this->actingAs($user)->postJson('/hotels/search', $payload)->assertOk();
        $second = $this->actingAs($user)->postJson('/hotels/search', $payload)->assertOk();

        $this->assertSame($first->json(), $second->json());
        $this->assertJsonPathsAreUsable($second);

        // One round trip for two searches: the second was answered from cache.
        Http::assertSentCount(1);
    }

    /**
     * A cached search that comes back as something unusable — an object graph left by
     * an older build, refused on the way out — is recomputed rather than served.
     */
    public function test_an_unreadable_cache_entry_is_recomputed(): void
    {
        Http::fake([self::BASE.'/Search' => Http::response($this->fixture('search'))]);

        $user = $this->userWith(['hotel.view', 'hotel.search']);
        $payload = $this->payload();

        $input = new SearchInput(
            checkIn: $payload['checkIn'],
            checkOut: $payload['checkOut'],
            rooms: [new PaxRoom(2, 0, [])],
            guestNationality: 'PH',
            locationType: 'city',
            locationCode: '127116',
        );

        Cache::put(
            app(HotelSearchCache::class)->key($user->id, 'test', $input),
            'not an array at all',
            600,
        );

        $this->actingAs($user)
            ->postJson('/hotels/search', $this->payload())
            ->assertOk()
            ->assertJsonCount(3, 'offers');
    }

    private function assertJsonPathsAreUsable(TestResponse $response): void
    {
        $response->assertJsonPath('currency', 'PHP')
            ->assertJsonPath('partial', false)
            ->assertJsonCount(3, 'offers');

        $this->assertIsArray($response->json('offers.0'));
        $this->assertArrayHasKey('lowestFare', $response->json('offers.0'));
    }

    /**
     * The panel renders the description as HTML, so the endpoint is the boundary
     * that has to make it safe — and it cleans on the way out, which means rows
     * already sitting in the catalogue are covered without a re-crawl.
     */
    public function test_the_description_is_sanitised_on_the_way_out(): void
    {
        Hotel::where('code', '1022346')->update([
            'description' => '<p><strong>Overview:</strong> Nice.</p><script>alert(1)</script>',
            'detailed_at' => now(),
        ]);

        $response = $this->actingAs($this->userWith(['hotel.view']))
            ->getJson('/hotels/1022346')
            ->assertOk();

        $description = $response->json('description');

        $this->assertStringContainsString('<strong>Overview:</strong>', $description);
        $this->assertStringNotContainsString('alert', $description);
    }

    /**
     * The nationality options are rendered server-side on purpose. Built by an x-for
     * template they do not exist yet when x-model runs, so the select falls back to
     * its first entry and the agent reads "Afghanistan" off a search priced for the
     * Philippines — the one field §18 says we cannot get wrong quietly.
     */
    public function test_the_nationality_options_are_rendered_server_side(): void
    {
        $this->actingAs($this->userWith(['hotel.view']))
            ->get('/hotels')
            ->assertOk()
            ->assertSee('<option value="PH">Philippines</option>', false);
    }

    public function test_hotels_appears_in_the_navigation(): void
    {
        $this->actingAs($this->userWith(['hotel.view']))
            ->get('/hotels')
            ->assertOk()
            ->assertSee(route('hotels'), false);
    }
}
