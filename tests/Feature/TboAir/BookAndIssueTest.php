<?php

namespace Tests\Feature\TboAir;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\TboAir\Exceptions\TboAirException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Book and Ticket — the money step. Every call here is faked; nothing reaches TBO.
 */
class BookAndIssueTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tboair/{$name}")), true);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
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

    private function booking(array $overrides = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'environment' => 'test',
            'status' => BookingStatus::Quoted,
            'trace_id' => 'trace-abc-123',
            'result_index' => str_repeat('R', 40),
            'is_lcc' => true,
            'total_amount' => '6400.00',
            'quote_raw' => $this->fixture('farequote.json'),
            'seats_available' => [9],
            'pax' => [[
                'type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz',
                'gender' => 'M', 'isLeadPax' => true, 'countryCode' => 'PH', 'countryName' => 'Philippines',
            ]],
        ], $overrides));
    }

    private function service(): BookingService
    {
        return app(BookingService::class);
    }

    // ---- The happy paths --------------------------------------------------

    public function test_an_lcc_goes_straight_from_quoted_to_ticketed(): void
    {
        $this->fake();

        $booking = $this->service()->issue($this->booking());

        $this->assertSame(BookingStatus::Ticketed, $booking->status);
        $this->assertSame('QWER12', $booking->pnr);
        $this->assertSame('884213', $booking->booking_id);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'Booking/Book'));
    }

    public function test_a_non_lcc_books_then_tickets(): void
    {
        $this->fake();

        $booked = $this->service()->book($this->booking(['is_lcc' => false]));
        $this->assertSame(BookingStatus::Booked, $booked->status);
        $this->assertSame('QWER12', $booked->pnr);

        $ticketed = $this->service()->issue($booked);
        $this->assertSame(BookingStatus::Ticketed, $ticketed->status);
    }

    /**
     * TBO's `Successful` means different things per call: a held PNR from Book, an
     * issued ticket from Ticket. Mapping Book's success through the ticketing table
     * would mark the booking Ticketed and skip the step that spends the money.
     */
    public function test_a_successful_book_is_booked_not_ticketed(): void
    {
        $this->fake();

        $booking = $this->service()->book($this->booking(['is_lcc' => false]));

        $this->assertSame(BookingStatus::Booked, $booking->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'Booking/Ticket'));
    }

    public function test_book_refuses_an_lcc(): void
    {
        $this->fake();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('booked and ticketed in one step');

        $this->service()->book($this->booking(['is_lcc' => true]));
    }

    // ---- Idempotency and ordering ----------------------------------------

    public function test_a_ticketed_booking_cannot_be_ticketed_again(): void
    {
        $this->fake();
        $booking = $this->service()->issue($this->booking());

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('is ticketed and cannot be ticketed');

        $this->service()->issue($booking);
    }

    public function test_a_non_lcc_cannot_be_ticketed_before_it_is_booked(): void
    {
        $this->fake();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('is quoted and cannot be ticketed');

        $this->service()->issue($this->booking(['is_lcc' => false]));
    }

    public function test_a_second_worker_is_refused_while_one_is_in_flight(): void
    {
        $this->fake();
        $booking = $this->booking();

        // Stand in for a worker mid-call: the lock is held, so the second caller
        // must be turned away rather than issuing a second ticket.
        Cache::lock("booking:{$booking->getKey()}:write", 120)->get();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('already being processed');

        $this->service()->issue($booking);
    }

    // ---- Reconciliation ---------------------------------------------------

    /**
     * Certification Case 11. InProgress is not a failure: read GetBookingDetails and
     * act on what it says.
     */
    public function test_an_in_progress_ticket_is_resolved_by_reading_the_booking(): void
    {
        $this->fake([
            '*Booking/Ticket*' => Http::response(['PNR' => 'QWER12', 'Status' => 8], 200),
        ]);

        $booking = $this->service()->issue($this->booking());

        $this->assertSame(BookingStatus::Ticketed, $booking->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'GetBookingDetails'));
    }

    /**
     * The case the live system gets wrong. An outcome nobody can establish must not be
     * called failed — that would refund the agency while a PNR may be live — and must
     * not be retried.
     */
    public function test_an_unresolvable_outcome_is_left_alone_for_a_person(): void
    {
        $this->fake([
            '*Booking/Ticket*' => Http::response(['PNR' => 'QWER12', 'Status' => 8], 200),
            '*GetBookingDetails*' => Http::response(['Response' => ['ResponseStatus' => 1, 'FlightItinerary' => [
                'PNR' => 'QWER12', 'Status' => 8,
            ]]], 200),
        ]);

        $booking = $this->booking();

        try {
            $this->service()->issue($booking);
            $this->fail('an unresolved outcome must not be reported as success');
        } catch (BookingException $e) {
            $this->assertTrue($e->unresolved);
            $this->assertStringContainsString('checked manually', $e->getMessage());
        }

        $booking->refresh();
        $this->assertSame(BookingStatus::Quoted, $booking->status, 'status must not move');
        $this->assertSame('QWER12', $booking->pnr, 'but the PNR must be recorded');
    }

    public function test_a_pnr_is_recorded_even_when_the_outcome_is_unresolved(): void
    {
        $this->fake([
            '*Booking/Ticket*' => Http::response(['PNR' => 'ZXCV99', 'Status' => 7], 200),
            '*GetBookingDetails*' => Http::response(['Response' => ['ResponseStatus' => 1, 'FlightItinerary' => [
                'PNR' => 'ZXCV99', 'Status' => 7,
            ]]], 200),
        ]);

        $booking = $this->booking();

        try {
            $this->service()->issue($booking);
        } catch (BookingException) {
            // expected
        }

        $this->assertSame('ZXCV99', $booking->fresh()->pnr);
    }

    /**
     * No PNR means TBO created nothing, so there is nothing to reconcile against and
     * failure is the honest reading.
     */
    public function test_an_ambiguous_status_with_no_pnr_is_a_failure(): void
    {
        $this->fake([
            '*Booking/Ticket*' => Http::response(['PNR' => '', 'Status' => 8], 200),
        ]);

        $booking = $this->booking();

        $this->assertThrows(fn () => $this->service()->issue($booking), BookingException::class);
        $this->assertSame(BookingStatus::Failed, $booking->fresh()->status);
    }

    public function test_an_outright_failure_marks_the_booking_failed_and_says_so(): void
    {
        $this->fake([
            '*Booking/Ticket*' => Http::response(['PNR' => '', 'Status' => 2], 200),
        ]);

        $booking = $this->booking();

        // A failed ticket must never travel back as a success — it did once.
        $this->assertThrows(fn () => $this->service()->issue($booking), BookingException::class);
        $this->assertSame(BookingStatus::Failed, $booking->fresh()->status);
    }

    /**
     * A failed Book must stop there. The live system falls through to Ticket with a
     * null PNR, which silently takes the LCC path and tries to issue an unbooked
     * itinerary.
     */
    public function test_a_failed_book_never_reaches_ticket(): void
    {
        $this->fake([
            '*Booking/Book*' => Http::response(['PNR' => '', 'Status' => 2], 200),
        ]);

        $booking = $this->booking(['is_lcc' => false]);

        $this->assertThrows(fn () => $this->service()->book($booking), BookingException::class);
        $this->assertSame(BookingStatus::Failed, $booking->fresh()->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'Booking/Ticket'));
    }

    /**
     * A real refused Book from the test host. Two things about it caught us out:
     * the whole body arrives wrapped in a one-element array, and an absent PNR is
     * the string "-". Read as an object it yields nothing — no status, no error —
     * so a genuine refusal surfaced as a blank fallback message.
     */
    public function test_it_reads_the_array_wrapped_refusal_tbo_actually_sends(): void
    {
        // The envelope from the real refused Book, with a business error rather than
        // the auth one (which re-authenticates instead — see the retry test).
        $body = $this->fixture('book-auth-failed.json');
        $body[0]['Status'] = 2;
        $body[0]['Errors'][0] = ['Code' => 50, 'UserMessage' => 'Seats no longer available'];

        $this->fake(['*Booking/Book*' => Http::response($body, 200)]);

        $booking = $this->booking(['is_lcc' => false]);

        try {
            $this->service()->book($booking);
            $this->fail('a refused Book must not return as though it succeeded');
        } catch (BookingException $e) {
            // Read as an object this body yields nothing at all, and the agent would
            // see a blank fallback instead of the supplier's reason.
            $this->assertStringContainsString('Seats no longer available', $e->getMessage());
        }

        $booking->refresh();
        $this->assertSame(BookingStatus::Failed, $booking->status);
        $this->assertNull($booking->pnr, '"-" is not a PNR');
    }

    /**
     * When re-authenticating does not help, the auth failure must surface rather than
     * be silently recorded as a booking failure.
     */
    public function test_a_persistent_auth_failure_is_raised_after_one_retry(): void
    {
        $this->fake(['*Booking/Book*' => Http::response($this->fixture('book-auth-failed.json'), 200)]);

        $this->expectException(TboAirException::class);
        $this->expectExceptionMessage('Authentication Failed');

        $this->service()->book($this->booking(['is_lcc' => false]));
    }

    /**
     * The booking host reports an expired session as Errors[0].Code 2, "Authentication
     * Failed" — not the ErrorCode 6 the search host uses — and it goes stale sooner
     * than search does. Recognising it is what turns a dead token into one retry
     * instead of a failed booking.
     */
    public function test_a_stale_token_on_the_booking_host_re_authenticates_and_retries(): void
    {
        $attempt = 0;

        Http::fake([
            '*Authenticate*' => Http::response($this->fixture('authenticate.json'), 200),
            '*GetBookingDetails*' => Http::response($this->fixture('bookingdetails.json'), 200),
            '*Booking/Book*' => function () use (&$attempt) {
                // First call: the stale-token refusal. Second: it works.
                return $attempt++ === 0
                    ? Http::response($this->fixture('book-auth-failed.json'), 200)
                    : Http::response(['PNR' => 'QWER12', 'BookingId' => 884213, 'Status' => 1], 200);
            },
        ]);

        $booking = $this->service()->book($this->booking(['is_lcc' => false]));

        $this->assertSame(BookingStatus::Booked, $booking->status);
        $this->assertSame('QWER12', $booking->pnr);
        $this->assertSame(2, $attempt, 'Book should have been retried exactly once');
    }

    /**
     * On a booking we ticketed for real, GetBookingDetails reported `Ticketed: false`
     * and a top-level Status of 5 (BookedOther) while carrying real ticket numbers.
     * Believing the status would record a paid, issued booking as merely held.
     */
    public function test_ticket_numbers_outrank_a_contradictory_status(): void
    {
        $ticketed = [
            'Status' => 5, // BookedOther — what TBO really said after a successful issue
            'IsSuccess' => true,
            'Itinerary' => [
                'PNR' => 'QWER12', 'BookingId' => 75133, 'Ticketed' => false,
                'Passenger' => [
                    ['PaxId' => 99470, 'FirstName' => 'Juan', 'LastName' => 'Cruz',
                        'Ticket' => ['TicketNumber' => '5014484654', 'Status' => 'OK', 'IssueDate' => '2026-08-10T08:54:40']],
                ],
            ],
        ];

        $this->fake([
            '*Booking/Ticket*' => Http::response(['PNR' => 'QWER12', 'Status' => 8], 200), // ambiguous
            '*GetBookingDetails*' => Http::response($ticketed, 200),
        ]);

        $booking = $this->service()->issue($this->booking());

        $this->assertSame(BookingStatus::Ticketed, $booking->status);
        $this->assertSame('5014484654', $booking->fresh()->pax[0]['ticketNumber']);
    }

    /**
     * TBO's PaxId is its own internal identifier (99470, not 1), so tickets are
     * matched by name — attaching one passenger's ticket to another would not be
     * caught later.
     */
    public function test_tickets_attach_to_the_right_passenger(): void
    {
        $this->fake([
            '*Booking/Ticket*' => Http::response(['PNR' => 'QWER12', 'Status' => 8], 200),
            '*GetBookingDetails*' => Http::response([
                'Status' => 1, 'IsSuccess' => true,
                'Itinerary' => ['PNR' => 'QWER12', 'Passenger' => [
                    // Deliberately the reverse of our stored order.
                    ['PaxId' => 99471, 'FirstName' => 'Maria', 'LastName' => 'Cruz',
                        'Ticket' => ['TicketNumber' => 'SECOND', 'Status' => 'OK']],
                    ['PaxId' => 99470, 'FirstName' => 'Juan', 'LastName' => 'Cruz',
                        'Ticket' => ['TicketNumber' => 'FIRST', 'Status' => 'OK']],
                ]],
            ], 200),
        ]);

        $booking = $this->booking(['pax' => [
            ['type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz', 'gender' => 'M', 'isLeadPax' => true, 'countryCode' => 'PH'],
            ['type' => 'Adult', 'title' => 'Mrs', 'firstName' => 'Maria', 'lastName' => 'Cruz', 'gender' => 'F', 'isLeadPax' => false, 'countryCode' => 'PH'],
        ]]);

        $pax = $this->service()->issue($booking)->fresh()->pax;

        $this->assertSame('FIRST', $pax[0]['ticketNumber'], 'Juan keeps his own ticket');
        $this->assertSame('SECOND', $pax[1]['ticketNumber']);
    }

    // ---- Guards -----------------------------------------------------------

    public function test_it_refuses_to_ticket_a_booking_from_another_environment(): void
    {
        $this->fake();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('was quoted on live');

        $this->service()->issue($this->booking(['environment' => 'live']));
    }

    /**
     * The balance is not pre-checked at all. TBO reports insufficient funds on the
     * Book/Ticket response itself, which is authoritative; a second source could only
     * disagree with it, and reading it costs a call that mints a token.
     */
    public function test_ticketing_does_not_read_the_supplier_balance(): void
    {
        $this->fake();

        $this->service()->issue($this->booking());

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'GetAvailableBalance'));
    }

    /**
     * ...which means TBO's own words are how a funds problem reaches the agent.
     */
    public function test_a_supplier_refusal_is_reported_in_tbos_own_words(): void
    {
        $this->fake([
            '*Booking/Ticket*' => Http::response([
                'PNR' => '', 'Status' => 2,
                'Error' => ['ErrorCode' => 12, 'ErrorMessage' => 'Insufficient balance in agency account'],
            ], 200),
        ]);

        $booking = $this->booking();

        try {
            $this->service()->issue($booking);
            $this->fail('a refused ticket must not return as though it succeeded');
        } catch (BookingException $e) {
            $this->assertStringContainsString('Insufficient balance in agency account', $e->getMessage());
        }

        $this->assertSame(BookingStatus::Failed, $booking->fresh()->status);
    }

    public function test_a_non_lcc_without_a_pnr_cannot_be_ticketed(): void
    {
        $this->fake();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('has no PNR to ticket');

        $this->service()->issue($this->booking(['is_lcc' => false, 'status' => BookingStatus::Booked, 'pnr' => null]));
    }
}
