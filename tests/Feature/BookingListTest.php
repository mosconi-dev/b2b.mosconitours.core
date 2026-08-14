<?php

namespace Tests\Feature;

use App\Enums\BookingProduct;
use App\Enums\BookingStatus;
use App\Enums\Supplier;
use App\Models\Booking;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The bookings list, once flights and hotels share it.
 *
 * One list carrying two products is only usable if a row says which product it is and
 * the list can be narrowed to one of them — these tests are about that narrowing, and
 * about the search an agent uses when they have a reference or a guest name in hand.
 */
class BookingListTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function agent(): User
    {
        return $this->userWith(['booking.view']);
    }

    private function flight(User $user, array $attributes = []): Booking
    {
        return Booking::factory()->create(array_replace([
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'reference' => 'MT-FLIGHT01',
            'quote' => [
                'trips' => [[
                    'direction' => 'outbound',
                    'segments' => [
                        ['origin' => ['code' => 'MNL'], 'destination' => ['code' => 'SIN']],
                        ['origin' => ['code' => 'SIN'], 'destination' => ['code' => 'BKK']],
                    ],
                ]],
            ],
        ], $attributes));
    }

    private function hotel(User $user, array $attributes = [], array $stay = []): Booking
    {
        $booking = Booking::factory()->create(array_replace([
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'reference' => 'MT-HOTEL001',
            'product' => BookingProduct::Hotel,
            'supplier' => Supplier::TboHotel,
            'status' => BookingStatus::Confirmed,
            'supplier_reference' => 'WM9CWM',
            'quote' => [],
        ], $attributes));

        $booking->hotel()->create(array_replace([
            'hotel_code' => '1012705', 'hotel_name' => 'Jen s Comfy Home', 'city' => 'Cebu',
            'check_in' => '2026-09-11', 'check_out' => '2026-09-13',
            'nights' => 2, 'rooms_count' => 1, 'guest_nationality' => 'PH',
            'booking_code' => 'code', 'confirmation_number' => 'WM9CWM',
        ], $stay));

        return $booking->fresh();
    }

    // ------------------------------------------------------------ the product tabs ----

    public function test_it_lists_both_products_by_default(): void
    {
        $user = $this->agent();
        $this->flight($user);
        $this->hotel($user);

        $this->actingAs($user)
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee('MT-FLIGHT01')
            ->assertSee('MT-HOTEL001');
    }

    public function test_it_narrows_the_list_to_one_product(): void
    {
        $user = $this->agent();
        $this->flight($user);
        $this->hotel($user);

        $this->actingAs($user)
            ->get(route('bookings.index', ['product' => 'hotel']))
            ->assertOk()
            ->assertSee('MT-HOTEL001')
            ->assertDontSee('MT-FLIGHT01');

        $this->actingAs($user)
            ->get(route('bookings.index', ['product' => 'flight']))
            ->assertOk()
            ->assertSee('MT-FLIGHT01')
            ->assertDontSee('MT-HOTEL001');
    }

    /**
     * `product` arrives from a link anyone can edit. A list is the wrong place to
     * answer a typo with an error page, so an unknown value means "all".
     */
    public function test_an_unknown_product_shows_everything(): void
    {
        $user = $this->agent();
        $this->flight($user);
        $this->hotel($user);

        $this->actingAs($user)
            ->get(route('bookings.index', ['product' => 'cruise']))
            ->assertOk()
            ->assertSee('MT-FLIGHT01')
            ->assertSee('MT-HOTEL001');
    }

    /**
     * The counts are what makes a tab worth pressing, and they must describe the
     * search rather than the tab that is open — otherwise switching tabs changes the
     * number the agent just decided to press.
     */
    public function test_the_tab_counts_follow_the_search_not_the_open_tab(): void
    {
        $user = $this->agent();
        $this->flight($user, ['reference' => 'MT-CEBU0001']);
        $this->hotel($user, ['reference' => 'MT-CEBU0002']);
        $this->hotel($user, ['reference' => 'MT-OTHER001'], ['hotel_name' => 'Manila Bay Suites', 'city' => 'Manila']);

        $response = $this->actingAs($user)->get(route('bookings.index', ['product' => 'flight', 'q' => 'MT-CEBU']));

        $response->assertOk()
            ->assertSee('MT-CEBU0001')
            ->assertDontSee('MT-OTHER001');

        $this->assertSame(
            ['all' => 2, 'flight' => 1, 'hotel' => 1],
            $response->viewData('counts'),
        );
    }

    // ---------------------------------------------------------------- the search ----

    public function test_it_searches_by_reference(): void
    {
        $user = $this->agent();
        $this->flight($user, ['reference' => 'MT-WANTED01']);
        $this->flight($user, ['reference' => 'MT-OTHER001']);

        $this->actingAs($user)
            ->get(route('bookings.index', ['q' => 'wanted']))
            ->assertOk()
            ->assertSee('MT-WANTED01')
            ->assertDontSee('MT-OTHER001');
    }

    public function test_it_searches_by_supplier_reference_and_pnr(): void
    {
        $user = $this->agent();
        $this->flight($user, ['reference' => 'MT-FLIGHT01', 'pnr' => 'ABC123']);
        $this->hotel($user);

        $this->actingAs($user)
            ->get(route('bookings.index', ['q' => 'ABC123']))
            ->assertOk()
            ->assertSee('MT-FLIGHT01')
            ->assertDontSee('MT-HOTEL001');

        $this->actingAs($user)
            ->get(route('bookings.index', ['q' => 'WM9CWM']))
            ->assertOk()
            ->assertSee('MT-HOTEL001')
            ->assertDontSee('MT-FLIGHT01');
    }

    /**
     * The property is on the hotel detail row, not the spine — searching it has to
     * reach through the relation or hotel bookings are unfindable by name.
     */
    public function test_it_searches_by_hotel_name(): void
    {
        $user = $this->agent();
        $this->hotel($user);
        $this->hotel($user, ['reference' => 'MT-HOTEL002'], ['hotel_name' => 'Manila Bay Suites', 'city' => 'Manila']);

        $this->actingAs($user)
            ->get(route('bookings.index', ['q' => 'Manila Bay']))
            ->assertOk()
            ->assertSee('MT-HOTEL002')
            ->assertDontSee('MT-HOTEL001');
    }

    public function test_it_searches_by_traveller_name(): void
    {
        $user = $this->agent();
        $this->flight($user, ['reference' => 'MT-FLIGHT01', 'pax' => [
            ['type' => 'Adult', 'firstName' => 'Juan', 'lastName' => 'Dela Cruz'],
        ]]);
        $this->flight($user, ['reference' => 'MT-OTHER001', 'pax' => [
            ['type' => 'Adult', 'firstName' => 'Maria', 'lastName' => 'Santos'],
        ]]);

        $this->actingAs($user)
            ->get(route('bookings.index', ['q' => 'Dela Cruz']))
            ->assertOk()
            ->assertSee('MT-FLIGHT01')
            ->assertDontSee('MT-OTHER001');
    }

    /**
     * A search is only useful if it cannot widen the list past what the agent is
     * allowed to see.
     */
    public function test_a_search_never_reaches_another_users_bookings(): void
    {
        $user = $this->agent();
        Booking::factory()->create(['reference' => 'MT-THEIRS01']);

        $this->actingAs($user)
            ->get(route('bookings.index', ['q' => 'MT-']))
            ->assertOk()
            ->assertDontSee('MT-THEIRS01');
    }

    /**
     * Page two of a filtered list must still be filtered — without the query string
     * on the paginator, pressing "next" quietly drops back to everything.
     */
    public function test_the_filter_survives_pagination(): void
    {
        $user = $this->agent();
        Booking::factory()->count(21)->create([
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'product' => BookingProduct::Flight,
        ]);

        $this->actingAs($user)
            ->get(route('bookings.index', ['product' => 'flight', 'q' => 'MT-']))
            ->assertOk()
            ->assertSee('product=flight&amp;q=MT-&amp;page=2', escape: false);
    }

    // ------------------------------------------------------- what a row says it is ----

    public function test_a_row_says_which_product_it_is(): void
    {
        $user = $this->agent();
        $this->flight($user);
        $this->hotel($user);

        $response = $this->actingAs($user)->get(route('bookings.index'))->assertOk();

        $response->assertSee('Flight')->assertSee('Hotel');
        // What was bought, not just that something was: the route for a flight, the
        // property for a hotel.
        $response->assertSee('MNL → BKK', escape: false)->assertSee('Jen s Comfy Home');
    }

    /**
     * Connections are not stops worth listing here — a scan wants where the trip went,
     * and a return continues the line it came from rather than repeating the airport.
     */
    public function test_a_multi_leg_itinerary_reads_as_one_leg_per_trip(): void
    {
        $user = $this->agent();
        $booking = $this->flight($user, ['quote' => ['trips' => [
            ['direction' => 'outbound', 'segments' => [
                ['origin' => ['code' => 'MNL'], 'destination' => ['code' => 'HKG']],
                ['origin' => ['code' => 'HKG'], 'destination' => ['code' => 'NRT']],
            ]],
            ['direction' => 'inbound', 'segments' => [
                ['origin' => ['code' => 'NRT'], 'destination' => ['code' => 'MNL']],
            ]],
        ]]]);

        $this->assertSame('MNL → NRT → MNL', $booking->itinerarySummary());
    }

    /**
     * An open jaw does not chain: flying home from a city you never flew into would
     * otherwise read as a leg that was never bought.
     */
    public function test_an_open_jaw_keeps_its_legs_apart(): void
    {
        $user = $this->agent();
        $booking = $this->flight($user, ['quote' => ['trips' => [
            ['direction' => 'outbound', 'segments' => [
                ['origin' => ['code' => 'MNL'], 'destination' => ['code' => 'NRT']],
            ]],
            ['direction' => 'inbound', 'segments' => [
                ['origin' => ['code' => 'KIX'], 'destination' => ['code' => 'MNL']],
            ]],
        ]]]);

        $this->assertSame('MNL → NRT · KIX → MNL', $booking->itinerarySummary());
    }

    public function test_a_booking_with_no_stored_trips_has_no_summary(): void
    {
        $user = $this->agent();

        $this->assertNull($this->flight($user, ['quote' => ['price' => []]])->itinerarySummary());
    }
}
