<?php

namespace Tests\Feature\TboHotel;

use App\Enums\BookingProduct;
use App\Enums\BookingStatus;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class HotelWizardTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    private const BASE = 'https://api.tbotechnology.in/HotelAPI';

    private const CODE = '1012705!TB!1!TB!f8cea260-96bf-11f1-a512-aa71e0cecaa6!TB!N!TB!AFF!';

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

        Hotel::create([
            'source' => 'tbo', 'code' => '1012705', 'city_code' => '127116',
            'country_code' => 'PH', 'name' => 'Jen s Comfy Home', 'rating' => 3,
        ]);

        // The property the search fixture actually has rates for — the rooms page runs
        // a Search, so a hotel absent from it renders "no rooms" and proves nothing.
        Hotel::create([
            'source' => 'tbo', 'code' => '1022346', 'city_code' => '127116',
            'country_code' => 'PH', 'name' => 'Fixture Suites', 'rating' => 4,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tbohotel/{$name}.json")), true);
    }

    private function fakePreBook(string $fixture = 'prebook'): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response($this->fixture($fixture))]);
    }

    private function agent(array $permissions = ['hotel.view', 'hotel.search', 'hotel.book']): User
    {
        $user = $this->userWith($permissions);
        $agency = Agency::factory()->create();
        Wallet::create(['agency_id' => $agency->id, 'currency' => 'PHP', 'balance' => '100000.00']);
        $user->forceFill(['agency_id' => $agency->id])->save();

        return $user->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function query(array $overrides = []): array
    {
        return array_replace([
            'bookingCode' => self::CODE,
            'checkIn' => '2026-09-11',
            'checkOut' => '2026-09-13',
            'locationCode' => '1012705',
            'guestNationality' => 'PH',
            'rooms' => '2-0',
            'shownFare' => '4036.02',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'bookingCode' => self::CODE,
            'checkIn' => '2026-09-11',
            'checkOut' => '2026-09-13',
            'locationCode' => '1012705',
            'guestNationality' => 'PH',
            'rooms' => [['adults' => 2, 'children' => 0, 'childrenAges' => []]],
            'guests' => [
                ['title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Dela Cruz', 'type' => 'Adult', 'roomIndex' => 0, 'isLead' => true],
                ['title' => 'Mrs', 'firstName' => 'Ana', 'lastName' => 'Dela Cruz', 'type' => 'Adult', 'roomIndex' => 0, 'isLead' => false],
            ],
            'contact' => ['email' => 'agent@example.test', 'phone' => '+639171234567'],
            'shownFare' => 4036.02,
            'acceptPriceChange' => false,
        ], $overrides);
    }

    public function test_the_wizard_opens_on_a_chosen_rate(): void
    {
        $this->fakePreBook();

        $this->actingAs($this->agent())
            ->get('/hotels/book?'.http_build_query($this->query()))
            ->assertOk()
            ->assertSee('Complete Booking')
            ->assertSee('Jen s Comfy Home')
            ->assertSee('Guest Details')
            ->assertSee('Select Hotel')
            ->assertSee('Select Room');
    }

    public function test_the_wizard_is_gated_on_booking(): void
    {
        $this->actingAs($this->userWith(['hotel.view', 'hotel.search']))
            ->get('/hotels/book?'.http_build_query($this->query()))
            ->assertForbidden();
    }

    /**
     * PreBook runs before the page renders: the terms an agent is about to accept must
     * be the supplier's current ones, not a results page from ten minutes ago.
     */
    public function test_it_reprices_before_rendering(): void
    {
        $this->fakePreBook();

        $this->actingAs($this->agent())->get('/hotels/book?'.http_build_query($this->query()))->assertOk();

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/PreBook'));
    }

    public function test_an_expired_rate_sends_the_agent_back_to_search(): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response(['Status' => ['Code' => 315, 'Description' => 'Session Expired']])]);

        $this->actingAs($this->agent())
            ->get('/hotels/book?'.http_build_query($this->query()))
            ->assertRedirect(route('hotels'))
            ->assertSessionHas('error', 'That rate has expired. Search again to see current availability.');
    }

    /**
     * The occupancy has to survive the hop, or the guest form offers the wrong number
     * of name fields. "2-0;2-1x8" is two rooms, the second with an eight-year-old.
     */
    public function test_the_occupancy_survives_the_url(): void
    {
        $this->fakePreBook('prebook-multiroom');

        $response = $this->actingAs($this->agent())
            ->get('/hotels/book?'.http_build_query($this->query(['rooms' => '2-0;2-1x8'])))
            ->assertOk();

        $rooms = $response->viewData('stay')['rooms'];

        $this->assertCount(2, $rooms);
        $this->assertSame(['adults' => 2, 'children' => 0, 'childrenAges' => []], $rooms[0]);
        $this->assertSame(['adults' => 2, 'children' => 1, 'childrenAges' => [8]], $rooms[1]);
    }

    public function test_a_price_move_arms_the_gate_on_render(): void
    {
        $this->fakePreBook();

        $this->actingAs($this->agent())
            ->get('/hotels/book?'.http_build_query($this->query(['shownFare' => '3500.00'])))
            ->assertOk()
            ->assertViewHas('priceChanged', true);
    }

    public function test_an_unchanged_price_leaves_the_gate_down(): void
    {
        $this->fakePreBook();

        $this->actingAs($this->agent())
            ->get('/hotels/book?'.http_build_query($this->query()))
            ->assertOk()
            ->assertViewHas('priceChanged', false);
    }

    public function test_submitting_creates_the_booking_and_redirects(): void
    {
        $this->fakePreBook();
        $user = $this->agent();

        $response = $this->actingAs($user)
            ->postJson('/hotels/bookings', $this->payload())
            ->assertOk()
            ->assertJsonStructure(['redirect', 'reference']);

        $booking = Booking::firstOrFail();

        $this->assertSame(BookingProduct::Hotel, $booking->product);
        $this->assertSame(BookingStatus::Quoted, $booking->status);
        $this->assertSame('4036.02', $booking->total_amount);
        $this->assertSame(route('bookings.show', $booking), $response->json('redirect'));
        $this->assertSame('Jen s Comfy Home', $booking->hotel->hotel_name);
    }

    public function test_storing_is_gated_on_booking(): void
    {
        $this->actingAs($this->userWith(['hotel.view', 'hotel.search']))
            ->postJson('/hotels/bookings', $this->payload())
            ->assertForbidden();
    }

    /**
     * The gate is the server's, not the browser's — a client that skips it is refused.
     */
    public function test_an_unaccepted_price_move_is_refused_with_both_figures(): void
    {
        $this->fakePreBook();

        $this->actingAs($this->agent())
            ->postJson('/hotels/bookings', $this->payload(['shownFare' => 3500.00]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'The hotel re-priced this room from 3,500.00 to 4,036.02. Confirm the new price to continue.');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_an_accepted_price_move_books_at_the_new_price(): void
    {
        $this->fakePreBook();

        $this->actingAs($this->agent())
            ->postJson('/hotels/bookings', $this->payload(['shownFare' => 3500.00, 'acceptPriceChange' => true]))
            ->assertOk();

        $this->assertSame('4036.02', Booking::firstOrFail()->total_amount);
    }

    public function test_guests_must_match_the_priced_occupancy(): void
    {
        $this->fakePreBook();

        $this->actingAs($this->agent())
            ->postJson('/hotels/bookings', $this->payload([
                'guests' => [
                    ['title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Dela Cruz', 'type' => 'Adult', 'roomIndex' => 0, 'isLead' => true],
                ],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Room 1 was priced for 2 adult(s) and 0 child(ren) — 1 and 0 were given.');
    }

    /**
     * TBO accepts Mr, Mrs and Ms only — the flight set would be refused at Book.
     */
    public function test_a_title_tbo_will_not_accept_is_refused(): void
    {
        $this->actingAs($this->agent())
            ->postJson('/hotels/bookings', $this->payload([
                'guests' => [
                    ['title' => 'Miss', 'firstName' => 'Ana', 'lastName' => 'Dela Cruz', 'type' => 'Adult', 'roomIndex' => 0, 'isLead' => true],
                    ['title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Dela Cruz', 'type' => 'Adult', 'roomIndex' => 0, 'isLead' => false],
                ],
            ]))
            ->assertJsonValidationErrors('guests.0.title');
    }

    public function test_a_nameless_guest_is_refused_before_tbo_sees_it(): void
    {
        $this->actingAs($this->agent())
            ->postJson('/hotels/bookings', $this->payload([
                'guests' => [
                    ['title' => 'Mr', 'firstName' => '', 'lastName' => 'Dela Cruz', 'type' => 'Adult', 'roomIndex' => 0, 'isLead' => true],
                ],
            ]))
            ->assertJsonValidationErrors('guests.0.firstName');
    }

    public function test_contact_details_are_required(): void
    {
        $this->actingAs($this->agent())
            ->postJson('/hotels/bookings', $this->payload(['contact' => ['email' => '', 'phone' => '']]))
            ->assertJsonValidationErrors(['contact.email', 'contact.phone']);
    }

    /**
     * A booking nobody paid for is worse than no booking.
     */
    public function test_a_short_wallet_refuses_and_creates_nothing(): void
    {
        $this->fakePreBook();

        $user = $this->userWith(['hotel.view', 'hotel.book']);
        $agency = Agency::factory()->create();
        Wallet::create(['agency_id' => $agency->id, 'currency' => 'PHP', 'balance' => '10.00']);
        $user->forceFill(['agency_id' => $agency->id])->save();

        $this->actingAs($user->fresh())
            ->postJson('/hotels/bookings', $this->payload())
            ->assertStatus(422);

        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('hotel_bookings', 0);
    }

    public function test_an_expired_rate_at_submit_is_reported_as_expired(): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response(['Status' => ['Code' => 315, 'Description' => 'Session Expired']])]);

        $this->actingAs($this->agent())
            ->postJson('/hotels/bookings', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('message', 'That rate has expired. Please search again.');
    }

    /**
     * The booking page is shared, and its flight half must not leak onto a hotel: the
     * Complete booking button there runs Book → Ticket against TBO Air.
     */
    public function test_the_booking_page_shows_the_stay_and_no_flight_actions(): void
    {
        $this->fakePreBook();
        $user = $this->agent(['hotel.view', 'hotel.book', 'booking.view']);

        $this->actingAs($user)->postJson('/hotels/bookings', $this->payload());
        $booking = Booking::firstOrFail();

        $this->actingAs($user)
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Jen s Comfy Home')
            ->assertSee('Check-in')
            ->assertSee('Not yet confirmed')
            ->assertSee('Guests')
            // Its reference is a confirmation number, and it has no trace or fare type.
            ->assertSee('Confirmation no.')
            ->assertDontSee('Not yet ticketed')
            ->assertDontSee('Complete booking')
            ->assertDontSee('Fare type')
            ->assertDontSee('>Trace<', false)
            ->assertDontSee('>PNR<', false)
            ->assertDontSee('>Passengers<', false);
    }

    /**
     * And the route itself refuses, so a hand-crafted POST cannot send a hotel's stored
     * quote to the airline API.
     */
    public function test_the_flight_fulfil_route_refuses_a_hotel_booking(): void
    {
        $this->fakePreBook();

        // The owner, holding every flight ability — so the refusal is about the
        // product and nothing else.
        $user = $this->agent(['hotel.view', 'hotel.book', 'booking.view', 'flight.book', 'flight.issue']);

        $this->actingAs($user)->postJson('/hotels/bookings', $this->payload());
        $booking = Booking::firstOrFail();

        $this->actingAs($user)
            ->post(route('bookings.fulfil', $booking))
            ->assertNotFound();

        $this->assertSame(BookingStatus::Quoted, $booking->fresh()->status);
    }

    /**
     * The results page is step 1 only. Choosing a room happens on the property's own
     * page, so this one carries the stepper and a link onward, not a Select button.
     */
    public function test_the_results_page_is_step_one_and_links_to_the_rooms_page(): void
    {
        $this->actingAs($this->agent())
            ->get('/hotels')
            ->assertOk()
            ->assertSee('Select Hotel')
            ->assertSee('Select Room')
            // The same control the flights list uses, not a text link.
            ->assertSee('selectHotel(offer)', false)
            ->assertDontSee('View rooms')
            // The wizard is two steps away now — nothing here jumps straight to it.
            ->assertDontSee(route('hotels.book'), false);
    }

    /**
     * @return array<string, string>
     */
    private function roomsQuery(array $overrides = []): array
    {
        return array_replace([
            'checkIn' => '2026-09-11',
            'checkOut' => '2026-09-13',
            'guestNationality' => 'PH',
            'rooms' => '2-0',
            'from' => '127116',
            'label' => 'Manila',
        ], $overrides);
    }

    public function test_the_rooms_page_lists_the_rates_for_one_property(): void
    {
        Http::fake([
            self::BASE.'/Search' => Http::response($this->fixture('search')),
            self::BASE.'/HotelDetails' => Http::response(['Status' => ['Code' => 500, 'Description' => 'nope']]),
        ]);

        $this->actingAs($this->agent())
            ->get('/hotels/1022346/rooms?'.http_build_query($this->roomsQuery()))
            ->assertOk()
            ->assertSee('Fixture Suites')
            ->assertSee('Select Room')
            // The search stays modifiable above the stepper, as on the flight wizard,
            // and there is exactly one way back — in the property card, not a banner.
            ->assertSee('Modify')
            ->assertSee('Change hotel')
            // The rates themselves, which is the point of the page.
            ->assertSee('3,790.37')
            ->assertSee('Select');
    }

    /**
     * The tabs are built from what the property actually has, so a tab never points at
     * a section that was not rendered.
     */
    public function test_the_section_tabs_match_the_sections_rendered(): void
    {
        Http::fake([
            self::BASE.'/Search' => Http::response($this->fixture('search')),
            self::BASE.'/HotelDetails' => Http::response(['Status' => ['Code' => 500, 'Description' => 'nope']]),
        ]);

        Hotel::where('code', '1022346')->update([
            'description' => '<p>A nice place.</p>',
            'facilities' => ['Pool', 'Wi-Fi'],
            'address' => '1 Somewhere Street',
            'latitude' => 14.55, 'longitude' => 121.02,
            'checkin_time' => '2:00 PM',
            'detailed_at' => now(),
        ]);

        $response = $this->actingAs($this->agent())
            ->get('/hotels/1022346/rooms?'.http_build_query($this->roomsQuery()))
            ->assertOk()
            ->assertSee('Overview')
            ->assertSee('Policies')
            // Renamed from "About this property".
            ->assertDontSee('About this property');

        $this->assertSame(
            ['overview', 'rooms', 'facilities', 'location', 'policies'],
            array_keys($response->viewData('sections')),
        );
    }

    /**
     * A property with nothing but rates gets one tab, not five dead ones.
     */
    public function test_a_bare_property_offers_only_the_tabs_it_has(): void
    {
        Http::fake([
            self::BASE.'/Search' => Http::response($this->fixture('search')),
            self::BASE.'/HotelDetails' => Http::response(['Status' => ['Code' => 500, 'Description' => 'nope']]),
        ]);

        Hotel::where('code', '1022346')->update([
            'description' => null, 'facilities' => null, 'address' => null,
            'latitude' => null, 'longitude' => null,
            'checkin_time' => null, 'checkout_time' => null,
            'detailed_at' => now(),
        ]);

        $sections = $this->actingAs($this->agent())
            ->get('/hotels/1022346/rooms?'.http_build_query($this->roomsQuery()))
            ->assertOk()
            ->viewData('sections');

        // Rooms always, plus policies only because the fixture's rates carry
        // at-property charges.
        $this->assertContains('rooms', array_keys($sections));
        $this->assertNotContains('overview', array_keys($sections));
        $this->assertNotContains('facilities', array_keys($sections));
        $this->assertNotContains('location', array_keys($sections));
    }

    /**
     * Leaving a property and coming back has to land on the search the agent ran, not
     * an empty form — that is the whole point of the step being its own page.
     */
    public function test_back_to_results_carries_the_search(): void
    {
        Http::fake([
            self::BASE.'/Search' => Http::response($this->fixture('search')),
            self::BASE.'/HotelDetails' => Http::response(['Status' => ['Code' => 500, 'Description' => 'nope']]),
        ]);

        $response = $this->actingAs($this->agent())
            ->get('/hotels/1022346/rooms?'.http_build_query($this->roomsQuery()))
            ->assertOk();

        $back = $response->viewData('backUrl');

        $this->assertStringContainsString('city=127116', $back);
        $this->assertStringContainsString('checkIn=2026-09-11', $back);
        $this->assertStringContainsString('rooms=2-0', $back);
    }

    public function test_the_rooms_page_says_so_when_nothing_is_left(): void
    {
        Http::fake([
            self::BASE.'/Search' => Http::response(['Status' => ['Code' => 201, 'Description' => 'No Available rooms']]),
            self::BASE.'/HotelDetails' => Http::response(['Status' => ['Code' => 500, 'Description' => 'nope']]),
        ]);

        $this->actingAs($this->agent())
            ->get('/hotels/1022346/rooms?'.http_build_query($this->roomsQuery()))
            ->assertOk()
            ->assertSee('No rooms available');
    }

    public function test_an_unknown_property_is_not_found(): void
    {
        $this->actingAs($this->agent())
            ->get('/hotels/9999999/rooms?'.http_build_query($this->roomsQuery()))
            ->assertNotFound();
    }

    /**
     * Without hotel.book the Select control is inert. The route enforces this too —
     * this is about not offering a door the agent cannot walk through.
     */
    public function test_an_agent_who_cannot_book_gets_no_select_link(): void
    {
        Http::fake([
            self::BASE.'/Search' => Http::response($this->fixture('search')),
            self::BASE.'/HotelDetails' => Http::response(['Status' => ['Code' => 500, 'Description' => 'nope']]),
        ]);

        $this->actingAs($this->agent(['hotel.view', 'hotel.search']))
            ->get('/hotels/1022346/rooms?'.http_build_query($this->roomsQuery()))
            ->assertOk()
            ->assertSee("You don't have booking permission", false)
            ->assertDontSee(route('hotels.book'), false);
    }
}
