<?php

namespace Tests\Feature\TboAir;

use App\Enums\BookingStatus;
use App\Jobs\FulfilBookingJob;
use App\Models\Booking;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The HTTP surface of the money step.
 *
 * There is no hold here by design: completing a booking queues Book → Ticket as one
 * act, the way the system live today does it. What these tests pin is that the queue is
 * what spends money — nothing reaches the supplier inside a request — and that the one
 * state which can strand a real PNR still has a way out.
 */
class TicketingRoutesTest extends TestCase
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

    private function fake(array $overrides = []): void
    {
        Http::fake(array_merge([
            '*Authenticate*' => Http::response($this->fixture('authenticate.json'), 200),
            '*GetAvailableBalance*' => Http::response($this->fixture('balance.json'), 200),
            '*Booking/Book*' => Http::response(['PNR' => 'QWER12', 'BookingId' => 884213, 'Status' => 1], 200),
            '*Booking/Ticket*' => Http::response(['PNR' => 'QWER12', 'BookingId' => 884213, 'Status' => 1], 200),
            '*GetBookingDetails*' => Http::response($this->fixture('bookingdetails.json'), 200),
        ], $overrides));
    }

    private function booking(User $user, array $overrides = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'environment' => 'test',
            'status' => BookingStatus::Quoted,
            'is_lcc' => true,
            'total_amount' => '6400.00',
            'trace_id' => 'trace-abc-123',
            'result_index' => str_repeat('R', 40),
            'quote_raw' => $this->fixture('farequote.json'),
            'seats_available' => [9],
            'pax' => [[
                'type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz',
                'gender' => 'M', 'isLeadPax' => true, 'countryCode' => 'PH', 'countryName' => 'Philippines',
            ]],
        ], $overrides));
    }

    private function agent(array $extra = []): User
    {
        return $this->userWith(array_merge(['booking.view', 'booking.create'], $extra));
    }

    // ---- Permissions ------------------------------------------------------

    public function test_completing_requires_the_issue_permission(): void
    {
        Queue::fake();
        $user = $this->agent(['flight.book']);

        $this->actingAs($user)
            ->post(route('bookings.fulfil', $this->booking($user)))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    /** It always ends in a ticket, so it needs the ability to book as well. */
    public function test_completing_requires_the_book_permission(): void
    {
        Queue::fake();
        $user = $this->agent(['flight.issue']);

        $this->actingAs($user)
            ->post(route('bookings.fulfil', $this->booking($user)))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_an_agent_cannot_complete_someone_elses_booking(): void
    {
        Queue::fake();
        $owner = $this->agent();
        $other = $this->agent(['flight.book', 'flight.issue']);

        $this->actingAs($other)
            ->post(route('bookings.fulfil', $this->booking($owner)))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    /**
     * The wizard refuses at the door rather than after ten minutes of passenger entry:
     * finishing it now issues a ticket.
     */
    public function test_the_wizard_is_closed_to_an_agent_who_cannot_issue(): void
    {
        $this->actingAs($this->agent())
            ->get(route('bookings.create', ['resultIndex' => 'x', 'traceId' => 'y']))
            ->assertForbidden();
    }

    // ---- Queueing ---------------------------------------------------------

    /**
     * Nothing may reach the supplier inside a web request. Ticket alone has taken 50
     * seconds against the real one.
     */
    public function test_completing_queues_the_work_and_touches_nothing_itself(): void
    {
        Queue::fake();
        $this->fake();
        $user = $this->agent(['flight.book', 'flight.issue']);
        $booking = $this->booking($user);

        $this->actingAs($user)
            ->post(route('bookings.fulfil', $booking))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $s): bool => str_contains($s, 'contacting the airline'));

        Queue::assertPushed(FulfilBookingJob::class, fn ($job): bool => $job->bookingId === $booking->id);
        Http::assertNothingSent();
        $this->assertSame(BookingStatus::Processing, $booking->fresh()->status);
    }

    /** A booking already at its ending cannot be sent round again. */
    public function test_a_ticketed_booking_cannot_be_completed_twice(): void
    {
        Queue::fake();
        $user = $this->agent(['flight.book', 'flight.issue']);
        $booking = $this->booking($user, ['status' => BookingStatus::Ticketed, 'pnr' => 'QWER12']);

        $this->actingAs($user)
            ->post(route('bookings.fulfil', $booking))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $s): bool => str_contains($s, 'cannot be completed'));

        Queue::assertNothingPushed();
    }

    /** The recovery path for a reservation that was made but never issued. */
    public function test_a_held_pnr_can_be_finished(): void
    {
        Queue::fake();
        $user = $this->agent(['flight.book', 'flight.issue']);
        $booking = $this->booking($user, [
            'is_lcc' => false, 'status' => BookingStatus::Booked, 'pnr' => 'QWER12',
        ]);

        $this->actingAs($user)->post(route('bookings.fulfil', $booking))->assertRedirect();

        Queue::assertPushed(FulfilBookingJob::class);
        $this->assertSame(BookingStatus::Booked, $booking->fresh()->status); // the job moves it, not the request
    }

    // ---- The page ---------------------------------------------------------

    public function test_the_page_never_offers_a_hold(): void
    {
        $user = $this->agent(['flight.book', 'flight.issue']);

        $this->actingAs($user)
            ->get(route('bookings.show', $this->booking($user, ['is_lcc' => false])))
            ->assertOk()
            ->assertDontSee('Hold PNR');
    }

    public function test_a_processing_booking_says_it_is_working(): void
    {
        $user = $this->agent(['flight.book', 'flight.issue']);
        $booking = $this->booking($user, ['status' => BookingStatus::Processing]);

        $this->actingAs($user)
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Contacting the airline')
            ->assertSee(route('bookings.status', $booking));   // it polls for the ending
    }

    public function test_a_held_booking_warns_that_no_ticket_exists(): void
    {
        $user = $this->agent(['flight.book', 'flight.issue']);
        $booking = $this->booking($user, [
            'is_lcc' => false, 'status' => BookingStatus::Booked, 'pnr' => 'QWER12',
        ]);

        $this->actingAs($user)
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Ticket not issued')
            ->assertSee('QWER12')
            ->assertSee('Finish ticketing');
    }

    /**
     * A failed booking that nonetheless holds a PNR must not invite a plain rebook —
     * that is how the same passengers get ticketed twice.
     */
    public function test_a_failed_booking_with_a_pnr_warns_before_rebooking(): void
    {
        $user = $this->agent(['flight.book', 'flight.issue']);
        $booking = $this->booking($user, ['status' => BookingStatus::Failed, 'pnr' => 'QWER12']);

        $this->actingAs($user)
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('did not complete')
            ->assertSee('ticketed twice');
    }

    public function test_a_ticketed_booking_offers_nothing_further(): void
    {
        $user = $this->agent(['flight.book', 'flight.issue']);

        $this->actingAs($user)
            ->get(route('bookings.show', $this->booking($user, ['status' => BookingStatus::Ticketed])))
            ->assertOk()
            ->assertDontSee('Complete booking')
            ->assertDontSee('Finish ticketing')
            ->assertDontSee('Hold PNR');
    }

    public function test_a_live_booking_is_flagged_before_completing(): void
    {
        $user = $this->agent(['flight.book', 'flight.issue']);

        $this->actingAs($user)
            ->get(route('bookings.show', $this->booking($user, ['environment' => 'live'])))
            ->assertOk()
            ->assertSee('This is a LIVE booking', false);
    }

    // ---- Polling ----------------------------------------------------------

    public function test_the_status_endpoint_reports_progress(): void
    {
        $user = $this->agent(['flight.book', 'flight.issue']);
        $booking = $this->booking($user, ['status' => BookingStatus::Processing]);

        $this->actingAs($user)
            ->getJson(route('bookings.status', $booking))
            ->assertOk()
            ->assertJson(['status' => 'processing', 'inFlight' => true]);
    }

    public function test_the_status_endpoint_reports_the_ending(): void
    {
        $user = $this->agent(['flight.book', 'flight.issue']);
        $booking = $this->booking($user, ['status' => BookingStatus::Ticketed, 'pnr' => 'QWER12']);

        $this->actingAs($user)
            ->getJson(route('bookings.status', $booking))
            ->assertOk()
            ->assertJson(['status' => 'ticketed', 'inFlight' => false, 'pnr' => 'QWER12']);
    }

    public function test_the_status_endpoint_is_closed_to_other_agents(): void
    {
        $owner = $this->agent();
        $other = $this->agent();

        $this->actingAs($other)
            ->getJson(route('bookings.status', $this->booking($owner)))
            ->assertForbidden();
    }
}
