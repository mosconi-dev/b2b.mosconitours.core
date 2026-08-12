<?php

namespace Tests\Feature\TboHotel;

use App\Models\Hotel;
use App\Models\HotelCity;
use App\Models\HotelCountry;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_hotels_appears_in_the_navigation(): void
    {
        $this->actingAs($this->userWith(['hotel.view']))
            ->get('/hotels')
            ->assertOk()
            ->assertSee(route('hotels'), false);
    }
}
