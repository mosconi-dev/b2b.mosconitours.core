<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\ETicket;
use App\Services\TboAir\DTO\FareQuote;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The printable e-ticket.
 *
 * This is the one page that leaves the building — an agency prints it and hands it to a
 * traveller who takes it to a check-in desk. So the things pinned here are mostly about
 * it telling the truth: a held PNR must not print the word "e-Ticket", a test-environment
 * booking must say so, and the passenger copy must genuinely omit the fare rather than
 * merely hiding it visually.
 */
class BookingETicketTest extends TestCase
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
            'total_amount' => '14858.75',
            'ancillary_total' => '0',
            'quote' => $quote,
            'quote_raw' => $this->fixture('farequote.json'),
            'contact' => [
                'email' => 'agent@example.com', 'phone' => '9171234567', 'mobileCountryCode' => '63',
                'addressLine1' => '123 Rizal Street', 'city' => 'Makati', 'countryCode' => 'PH',
            ],
            'pax' => [[
                'type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Dela Cruz',
                'gender' => 'M', 'dateOfBirth' => '1990-08-15', 'isLeadPax' => true,
                'nationality' => 'PH',
                'documentNumber' => 'UMID-1234-5678901', 'documentExpiry' => '2032-08-18',
                'ticketNumber' => '5014484654', 'ticketIssuedAt' => '2026-08-10T08:54:40',
            ]],
        ], $overrides));
    }

    public function test_it_prints_the_whole_journey(): void
    {
        $user = $this->userWith(['booking.view']);

        $this->actingAs($user)
            ->get(route('bookings.eticket', $this->booking($user)))
            ->assertOk()
            ->assertSee('Manila (MNL) to Cebu (CEB)')
            ->assertSee('Cebu Pacific')
            ->assertSee('5J561')
            ->assertSee('Terminal 3')                  // the gate the passenger has to find
            ->assertSee('Ninoy Aquino', false)
            ->assertSee('984XIX')                      // airline PNR
            ->assertSee('75133');                      // airline booking id
    }

    public function test_it_prints_the_passenger_and_their_ticket(): void
    {
        $user = $this->userWith(['booking.view']);

        $this->actingAs($user)
            ->get(route('bookings.eticket', $this->booking($user)))
            ->assertOk()
            ->assertSee('e-Ticket')
            ->assertSee('JUAN DELA CRUZ')              // as it must read at check-in
            ->assertSee('5014484654')
            ->assertSee('UMID-1234-5678901')
            ->assertSee('Expires 2032-08-18');
    }

    /**
     * The heading follows the ticket numbers, not the status.
     *
     * The live system prints "e-Ticket" over a held PNR, which hands the traveller a
     * document claiming a ticket nobody paid for.
     */
    public function test_a_held_booking_does_not_call_itself_an_eticket(): void
    {
        $user = $this->userWith(['booking.view']);

        $booking = $this->booking($user, [
            'status' => BookingStatus::Booked,
            'pax' => [[
                'type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Dela Cruz',
                'isLeadPax' => true, 'documentNumber' => 'UMID-1234-5678901', 'dateOfBirth' => '1990-08-15']],
        ]);

        $this->actingAs($user)
            ->get(route('bookings.eticket', $booking))
            ->assertOk()
            ->assertSee('Booking Confirmation')
            ->assertSee('This is a reservation, not a ticket.')
            ->assertSee('Not issued')
            ->assertDontSee('>e-Ticket<', false);      // not as the heading
    }

    /** A test-environment printout must never pass for a travel document. */
    public function test_a_test_booking_says_it_is_not_valid_for_travel(): void
    {
        $user = $this->userWith(['booking.view']);

        $this->actingAs($user)
            ->get(route('bookings.eticket', $this->booking($user)))
            ->assertOk()
            ->assertSee('NOT VALID FOR TRAVEL');
    }

    /** The copy handed to the traveller omits what the agency paid. */
    public function test_the_passenger_copy_omits_the_fare(): void
    {
        $user = $this->userWith(['booking.view']);

        $booking = $this->booking($user);
        $quote = $booking->quote;
        $quote['fareBreakdown'] = [[
            'passengerType' => 'Adult', 'count' => 2, 'baseFare' => 11852.16, 'tax' => 3006.59,
        ]];
        $booking->update(['quote' => $quote]);

        // With fares: the real two-adult figures, not doubled.
        $this->actingAs($user)
            ->get(route('bookings.eticket', $booking))
            ->assertOk()
            ->assertSee('14,858.75')
            ->assertSee('5,926.08')                    // base fare per head
            ->assertDontSee('29,717.50');              // what multiplying by count again would give

        // Passenger copy: gone entirely, not merely hidden.
        $this->actingAs($user)
            ->get(route('bookings.eticket', [$booking, 'prices' => 0]))
            ->assertOk()
            ->assertSee('JUAN DELA CRUZ')
            ->assertDontSee('14,858.75')
            ->assertDontSee('11,852.16');
    }

    /**
     * The printed fare must add up.
     *
     * The per-passenger lines sum to TBO's PublishedFare, but we charge its OfferedFare
     * — ten centavos more on PNR 984XIX. Unreconciled, the document shows a total that
     * disagrees with its own subtotals.
     */
    public function test_the_fare_lines_sum_to_the_total(): void
    {
        $user = $this->userWith(['booking.view']);

        $booking = $this->booking($user, ['total_amount' => '14858.85']);
        $quote = $booking->quote;
        $quote['fareBreakdown'] = [[
            'passengerType' => 'Adult', 'count' => 2, 'baseFare' => 11852.16, 'tax' => 3006.59,
        ]];
        $booking->update(['quote' => $quote]);

        $ticket = ETicket::for($booking->fresh());

        $this->assertSame(0.10, $ticket->otherCharges());

        $this->actingAs($user)
            ->get(route('bookings.eticket', $booking))
            ->assertOk()
            ->assertSee('Other charges')
            ->assertSee('14,858.85');
    }

    /** No reconciling line when the lines already add up. */
    public function test_it_prints_no_other_charges_line_when_the_fare_is_exact(): void
    {
        $user = $this->userWith(['booking.view']);

        $booking = $this->booking($user, ['total_amount' => '14858.75']);
        $quote = $booking->quote;
        $quote['fareBreakdown'] = [[
            'passengerType' => 'Adult', 'count' => 2, 'baseFare' => 11852.16, 'tax' => 3006.59,
        ]];
        $booking->update(['quote' => $quote]);

        $this->actingAs($user)
            ->get(route('bookings.eticket', $booking))
            ->assertOk()
            ->assertDontSee('Other charges');
    }

    public function test_it_names_the_operating_carrier_when_it_differs(): void
    {
        $user = $this->userWith(['booking.view']);

        // PNR 984XIX really was sold by PR and flown by 2P — the desk the passenger
        // has to find is the operating carrier's.
        $raw = $this->fixture('farequote.json');
        $raw['Response']['Results']['Segments'][0][0]['Airline']['OperatingCarrier'] = '2P';

        $this->actingAs($user)
            ->get(route('bookings.eticket', $this->booking($user, ['quote_raw' => $raw])))
            ->assertOk()
            ->assertSee('Operated by 2P');
    }

    /**
     * The agency's own branding, so the traveller knows who to call.
     *
     * They booked with the agency, not with us and not with the airline — when a flight
     * moves, the agency is who they ring.
     */
    public function test_it_carries_the_agencys_branding_and_contact_details(): void
    {
        $agency = Agency::factory()->create([
            'name' => 'Mosconi Tours',
            'logo_path' => 'agency-logos/mosconi.png',
            'contact_email' => 'support@mosconitours.test',
            'contact_phone' => '+63 2 8888 1234',
        ]);
        $user = $this->agencyUserWith($agency, ['booking.view']);

        $this->actingAs($user)
            ->get(route('bookings.eticket', $this->booking($user)))
            ->assertOk()
            ->assertSee('Mosconi Tours')
            ->assertSee('agency-logos/mosconi.png')       // their logo, not ours
            ->assertSee('support@mosconitours.test')
            ->assertSee('+63 2 8888 1234')
            ->assertSee('Need help with this booking?');
    }

    /**
     * An agency that has not uploaded a logo or filled in a contact still gets a usable
     * document: our mark stands in, and the agent who made the booking is the contact.
     */
    public function test_it_falls_back_to_our_logo_and_the_booking_agent(): void
    {
        $agency = Agency::factory()->create([
            'name' => 'Sunrise Travel',
            'logo_path' => null,
            'contact_email' => null,
            'contact_phone' => null,
        ]);
        $user = $this->agencyUserWith($agency, ['booking.view']);

        $this->actingAs($user)
            ->get(route('bookings.eticket', $this->booking($user)))
            ->assertOk()
            ->assertSee('Sunrise Travel')          // still their name
            ->assertSee('favicon.png')             // our mark stands in
            ->assertSee($user->email);             // the agent who booked it
    }

    /** A quote has no reservation behind it, so there is nothing to print. */
    public function test_a_booking_without_a_pnr_cannot_be_printed(): void
    {
        $user = $this->userWith(['booking.view']);

        $booking = $this->booking($user, ['pnr' => null, 'status' => BookingStatus::Quoted]);

        $this->actingAs($user)->get(route('bookings.eticket', $booking))->assertNotFound();
    }

    public function test_it_is_not_readable_by_another_user(): void
    {
        $owner = $this->userWith(['booking.view']);
        $stranger = $this->userWith(['booking.view']);

        $this->actingAs($stranger)
            ->get(route('bookings.eticket', $this->booking($owner)))
            ->assertForbidden();
    }
}
