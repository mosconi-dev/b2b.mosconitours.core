<?php

namespace Tests\Feature\TboAir;

use App\Models\Booking;
use App\Models\User;
use App\Services\TboAir\ItineraryMapper;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * TBO returns `NoOfSeatAvailable` on every search segment and drops it from the
 * FareQuote response, but Book wants it back. It is the only Book-relevant field lost
 * between the two calls, so it has to be captured at search and carried to the
 * booking. These cover that path end to end.
 */
class SeatAvailabilityTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tboair/{$name}")), true);
    }

    private function bookingUser(): User
    {
        return $this->userWith(['flight.view', 'flight.search', 'booking.view', 'booking.create']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'traceId' => 'trace-abc-123',
            'resultIndex' => str_repeat('R', 400),
            'contact' => [
                'email' => 'agent@example.com', 'phone' => '09170000000', 'mobileCountryCode' => '63',
                'addressLine1' => '123 Rizal Street', 'city' => 'Makati', 'countryCode' => 'PH',
            ],
            'passengers' => [
                ['type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz', 'gender' => 'M'],
            ],
        ], $overrides);
    }

    /**
     * @return array<int, int|null>
     */
    private function mappedSeats(array $segments): array
    {
        $mapper = new ItineraryMapper;

        return array_column($mapper->legs($mapper->trips($segments)), 'seats');
    }

    public function test_the_mapper_captures_seats_from_search_segments(): void
    {
        $results = $this->fixture('search-oneway.json')['Response']['Results'][0];

        $this->assertSame([9], $this->mappedSeats($results[0]['Segments']), 'non-stop');
        $this->assertSame([9, 8], $this->mappedSeats($results[1]['Segments']), 'one stop, per leg');
    }

    /**
     * The same mapper runs over FareQuote segments, which have no such field. It must
     * yield null rather than 0 — "not captured" and "no seats" are different facts.
     */
    public function test_the_mapper_yields_null_when_the_field_is_absent(): void
    {
        $seats = $this->mappedSeats($this->fixture('farequote.json')['Response']['Results']['Segments']);

        $this->assertNotEmpty($seats);
        $this->assertSame(array_fill(0, count($seats), null), $seats);
    }

    public function test_search_results_expose_seats_to_the_flights_page(): void
    {
        Http::fake([
            '*Authenticate*' => Http::response($this->fixture('authenticate.json'), 200),
            '*Search*' => Http::response($this->fixture('search-oneway.json'), 200),
        ]);

        $response = $this->actingAs($this->bookingUser())->postJson(route('flights.search'), [
            'tripType' => 'oneway',
            'cabin' => 'economy',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
            'segments' => [[
                'origin' => 'MNL',
                'destination' => 'CEB',
                'departure' => now()->addDays(20)->format('Y-m-d'),
            ]],
        ]);

        $response->assertOk();

        // Every leg of every offer must carry what search reported; the results list is
        // sorted, so compare the collected set rather than a fixed position.
        $seats = [];
        foreach ($response->json('results') as $offer) {
            foreach ($offer['trips'] as $trip) {
                $seats = array_merge($seats, array_column($trip['segments'], 'seats'));
            }
        }
        sort($seats);

        $this->assertSame([8, 9, 9], $seats);
    }

    public function test_a_booking_persists_the_seats_it_was_given(): void
    {
        Http::fake([
            '*Authenticate*' => Http::response($this->fixture('authenticate.json'), 200),
            '*FareQuote*' => Http::response($this->fixture('farequote.json'), 200),
            '*SSR*' => Http::response($this->fixture('ssr.json'), 200),
        ]);

        $this->actingAs($this->bookingUser())
            ->post(route('bookings.store'), $this->payload(['seats' => [9, 8, 7]]))
            ->assertRedirect();

        $this->assertSame([9, 8, 7], Booking::firstOrFail()->seats_available);
    }

    public function test_a_booking_without_seats_stores_an_empty_list(): void
    {
        Http::fake([
            '*Authenticate*' => Http::response($this->fixture('authenticate.json'), 200),
            '*FareQuote*' => Http::response($this->fixture('farequote.json'), 200),
            '*SSR*' => Http::response($this->fixture('ssr.json'), 200),
        ]);

        $this->actingAs($this->bookingUser())
            ->post(route('bookings.store'), $this->payload())
            ->assertRedirect();

        $this->assertSame([], Booking::firstOrFail()->seats_available);
    }

    public function test_the_wizard_carries_seats_from_the_query_string(): void
    {
        Http::fake([
            '*Authenticate*' => Http::response($this->fixture('authenticate.json'), 200),
            '*FareQuote*' => Http::response($this->fixture('farequote.json'), 200),
            '*SSR*' => Http::response($this->fixture('ssr.json'), 200),
        ]);

        $this->actingAs($this->bookingUser())
            ->get(route('bookings.create', [
                'traceId' => 'trace-abc-123',
                'resultIndex' => str_repeat('R', 400),
                'seats' => '9,8,7',
            ]))
            ->assertOk()
            ->assertViewHas('seats', [9, 8, 7]);
    }

    /**
     * The wizard must receive the seats in its Alpine config, not merely in the view
     * data. It once did not: the blade passed them and the component never declared
     * the property, so every booking posted an empty list.
     */
    public function test_the_wizard_config_carries_the_seats_to_the_browser(): void
    {
        Http::fake([
            '*Authenticate*' => Http::response($this->fixture('authenticate.json'), 200),
            '*FareQuote*' => Http::response($this->fixture('farequote.json'), 200),
            '*SSR*' => Http::response($this->fixture('ssr.json'), 200),
        ]);

        $this->actingAs($this->bookingUser())
            ->get(route('bookings.create', [
                'traceId' => 'trace-abc-123',
                'resultIndex' => str_repeat('R', 400),
                'seats' => '9,8,7',
                'resultType' => 1,
            ]))
            ->assertOk()
            // @js() escapes quotes as \u0022 inside the x-data attribute.
            ->assertSee('seats\\u0022:[9,8,7]', false)
            ->assertSee('resultType\\u0022:1', false);
    }

    /**
     * A blank entry means that segment's availability was never captured. It must stay
     * null, not become 0 — only one of those should ever be able to stop a booking.
     */
    public function test_a_blank_entry_stays_null_rather_than_zero(): void
    {
        Http::fake([
            '*Authenticate*' => Http::response($this->fixture('authenticate.json'), 200),
            '*FareQuote*' => Http::response($this->fixture('farequote.json'), 200),
            '*SSR*' => Http::response($this->fixture('ssr.json'), 200),
        ]);

        $this->actingAs($this->bookingUser())
            ->get(route('bookings.create', [
                'traceId' => 'trace-abc-123',
                'resultIndex' => str_repeat('R', 400),
                'seats' => '9,,7',
            ]))
            ->assertOk()
            ->assertViewHas('seats', [9, null, 7]);
    }

    public function test_the_wizard_rejects_a_malformed_seat_list(): void
    {
        $this->actingAs($this->bookingUser())
            ->get(route('bookings.create', [
                'traceId' => 'trace-abc-123',
                'resultIndex' => str_repeat('R', 400),
                'seats' => '9;DROP',
            ]))
            ->assertSessionHasErrors('seats');
    }
}
