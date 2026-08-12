<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\TboAir\DTO\FareQuote;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * What the booking page must actually show. Everything here was already stored and
 * simply not rendered — a booking page that omits the flight, the ticket numbers or
 * the real fare is worse than no page, because it looks authoritative.
 */
class BookingShowTest extends TestCase
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

    private function booking(User $user, array $overrides = []): Booking
    {
        $quote = FareQuote::fromResponse($this->fixture('farequote.json'))->toArray();

        return Booking::factory()->create(array_merge([
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'environment' => 'test',
            'status' => BookingStatus::Ticketed,
            'is_lcc' => false,
            'pnr' => '984XIX',
            'booking_id' => '75133',
            'currency' => 'PHP',
            'total_amount' => '14858.85',
            'quote' => $quote,
            'quote_raw' => $this->fixture('farequote.json'),
            'contact' => [
                'email' => 'agent@example.com', 'phone' => '9171234567', 'mobileCountryCode' => '63',
                'addressLine1' => '123 Rizal Street', 'city' => 'Makati', 'countryCode' => 'PH',
            ],
            'pax' => [[
                'type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Dela Cruz',
                'gender' => 'M', 'dateOfBirth' => '1990-08-15', 'isLeadPax' => true,
                'documentNumber' => 'UMID-1234-5678901', 'documentExpiry' => '2032-08-18',
                'ticketNumber' => '5014484654', 'ticketIssuedAt' => '2026-08-10T08:54:40',
            ]],
        ], $overrides));
    }

    public function test_it_shows_the_flight_that_was_booked(): void
    {
        $user = $this->userWith(['booking.view']);

        $this->actingAs($user)
            ->get(route('bookings.show', $this->booking($user)))
            ->assertOk()
            ->assertSee('Itinerary')
            ->assertSee('5J561')          // flight number from the quote
            ->assertSee('MNL → CEB', false);
    }

    public function test_it_shows_the_ticket_number(): void
    {
        $user = $this->userWith(['booking.view']);

        $this->actingAs($user)
            ->get(route('bookings.show', $this->booking($user)))
            ->assertOk()
            ->assertSee('5014484654')
            ->assertSee('75133');         // the airline's own booking id
    }

    /**
     * TBO's FareBreakdown entry already covers every passenger of its type, so
     * multiplying by the count showed double the real total.
     */
    public function test_the_fare_breakdown_matches_the_total(): void
    {
        $user = $this->userWith(['booking.view']);

        // The real two-adult breakdown from PNR 984XIX: these figures already cover
        // both passengers, which is what the old row multiplied a second time.
        $booking = $this->booking($user);
        $quote = $booking->quote;
        $quote['fareBreakdown'] = [[
            'passengerType' => 'Adult', 'count' => 2, 'baseFare' => 11852.16, 'tax' => 3006.59,
        ]];
        $booking->update(['quote' => $quote]);

        $this->actingAs($user)
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('14,858.75')        // the line total, matching what was charged
            ->assertSee('7,429.38')         // and per head
            ->assertDontSee('29,717.50');   // what doubling produced
    }

    public function test_it_shows_the_document_the_passenger_gave(): void
    {
        $user = $this->userWith(['booking.view']);

        $this->actingAs($user)
            ->get(route('bookings.show', $this->booking($user)))
            ->assertOk()
            ->assertSee('UMID-1234-5678901')
            ->assertSee('123 Rizal Street');
    }
}
