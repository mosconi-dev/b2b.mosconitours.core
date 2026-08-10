<?php

namespace Tests\Feature\TboAir;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Booking\Exceptions\BookingException;
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

        $booking = $this->service()->issue($this->booking());

        $this->assertSame(BookingStatus::Failed, $booking->status);
    }

    public function test_an_outright_failure_marks_the_booking_failed(): void
    {
        $this->fake([
            '*Booking/Ticket*' => Http::response(['PNR' => '', 'Status' => 2], 200),
        ]);

        $this->assertSame(BookingStatus::Failed, $this->service()->issue($this->booking())->status);
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

        $booking = $this->service()->book($this->booking(['is_lcc' => false]));

        $this->assertSame(BookingStatus::Failed, $booking->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'Booking/Ticket'));
    }

    // ---- Guards -----------------------------------------------------------

    public function test_it_refuses_to_ticket_a_booking_from_another_environment(): void
    {
        $this->fake();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('was quoted on live');

        $this->service()->issue($this->booking(['environment' => 'live']));
    }

    public function test_it_refuses_to_ticket_when_our_supplier_balance_is_short(): void
    {
        $this->fake([
            '*GetAvailableBalance*' => Http::response(['Currency' => 'PHP', 'TotalAvailableLimit' => '10.00', 'IsSuccess' => true], 200),
        ]);

        try {
            $this->service()->issue($this->booking());
            $this->fail('ticketing must stop when the supplier account cannot cover it');
        } catch (BookingException $e) {
            $this->assertStringContainsString('supplier has insufficient funds', $e->getMessage());
        }

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'Booking/Ticket'));
        $this->assertSame(BookingStatus::Quoted, $this->booking()->status);
    }

    /**
     * A balance we cannot read is not a reason to block a sale — the supplier will
     * reject it anyway if the funds really are short.
     */
    public function test_an_unreadable_balance_does_not_block_ticketing(): void
    {
        $this->fake([
            '*GetAvailableBalance*' => Http::response(['IsSuccess' => false, 'ErrorMessage' => 'nope'], 200),
        ]);

        $this->assertSame(BookingStatus::Ticketed, $this->service()->issue($this->booking())->status);
    }

    public function test_a_non_lcc_without_a_pnr_cannot_be_ticketed(): void
    {
        $this->fake();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('has no PNR to ticket');

        $this->service()->issue($this->booking(['is_lcc' => false, 'status' => BookingStatus::Booked, 'pnr' => null]));
    }
}
