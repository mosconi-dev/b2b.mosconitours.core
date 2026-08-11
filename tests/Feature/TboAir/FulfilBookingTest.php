<?php

namespace Tests\Feature\TboAir;

use App\Enums\BookingStatus;
use App\Jobs\FulfilBookingJob;
use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Booking\Exceptions\BookingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The one-press transaction: Complete booking → tickets, with no hold in between.
 *
 * This mirrors how the system live today behaves — non-LCC runs Book then Ticket back
 * to back, LCC runs Ticket alone — and fixes the defect in that system's version of it.
 * Every call is faked; nothing reaches TBO.
 */
class FulfilBookingTest extends TestCase
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
            'status' => BookingStatus::Processing,
            'trace_id' => 'trace-abc-123',
            'result_index' => str_repeat('R', 40),
            'is_lcc' => true,
            'total_amount' => '6400.00',
            'quote_raw' => $this->fixture('farequote.json'),
            'seats_available' => [9],
            'pax' => [[
                'type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz',
                'gender' => 'M', 'isLeadPax' => true, 'countryCode' => 'PH', 'countryName' => 'Philippines', 'dateOfBirth' => '1990-08-15']],
        ], $overrides));
    }

    private function service(): BookingService
    {
        return app(BookingService::class);
    }

    public function test_a_non_lcc_books_and_tickets_in_one_press(): void
    {
        $this->fake();

        $booking = $this->service()->fulfil($this->booking(['is_lcc' => false]));

        $this->assertSame(BookingStatus::Ticketed, $booking->status);
        $this->assertSame('QWER12', $booking->pnr);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'Booking/Book'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'Booking/Ticket'));
    }

    /** LCC has no Book step at all — Ticket books and issues together. */
    public function test_an_lcc_only_tickets(): void
    {
        $this->fake();

        $booking = $this->service()->fulfil($this->booking(['is_lcc' => true]));

        $this->assertSame(BookingStatus::Ticketed, $booking->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'Booking/Book'));
    }

    /**
     * The defect this flow exists to avoid.
     *
     * The live system sets a failure status when Book fails but does not return, so it
     * calls Ticket anyway with a null book response — ticketing against a reservation
     * that was never made. Ours must never reach Ticket.
     */
    public function test_a_failed_book_never_reaches_ticket(): void
    {
        $this->fake([
            '*Booking/Book*' => Http::response([[
                'PNR' => '-',
                'Errors' => [['Code' => 1, 'UserMessage' => 'Insufficient agency balance.']],
            ]], 200),
        ]);

        $booking = $this->booking(['is_lcc' => false]);

        try {
            $this->service()->fulfil($booking);
            $this->fail('Expected the chain to stop when Book was refused.');
        } catch (BookingException $e) {
            $this->assertStringContainsString('Insufficient agency balance', $e->getMessage());
        }

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'Booking/Ticket'));
        $this->assertSame(BookingStatus::Failed, $booking->fresh()->status);
    }

    /** A held PNR resumes at Ticket rather than reserving the seats a second time. */
    public function test_it_resumes_at_ticket_when_a_pnr_is_already_held(): void
    {
        $this->fake();

        $booking = $this->booking([
            'is_lcc' => false,
            'status' => BookingStatus::Booked,
            'pnr' => 'QWER12',
            'booking_id' => '884213',
        ]);

        $this->assertSame(BookingStatus::Ticketed, $this->service()->fulfil($booking)->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'Booking/Book'));
    }

    // ---- The job ----------------------------------------------------------

    public function test_the_job_takes_a_processing_booking_to_ticketed(): void
    {
        $this->fake();

        $booking = $this->booking();
        (new FulfilBookingJob($booking->id))->handle($this->service());

        $this->assertSame(BookingStatus::Ticketed, $booking->fresh()->status);
    }

    /**
     * A second delivery of the same job must not buy a second ticket.
     */
    public function test_the_job_leaves_a_settled_booking_alone(): void
    {
        $this->fake();

        $booking = $this->booking(['status' => BookingStatus::Ticketed, 'pnr' => 'QWER12']);
        (new FulfilBookingJob($booking->id))->handle($this->service());

        Http::assertNothingSent();
        $this->assertSame(BookingStatus::Ticketed, $booking->fresh()->status);
    }

    /** A refusal is recorded on the booking, not thrown out of the job into a retry. */
    public function test_the_job_records_a_refusal_without_rethrowing(): void
    {
        $this->fake([
            '*Booking/Ticket*' => Http::response([[
                'PNR' => '-',
                'Errors' => [['Code' => 1, 'UserMessage' => 'Fare no longer available.']],
            ]], 200),
        ]);

        $booking = $this->booking();
        (new FulfilBookingJob($booking->id))->handle($this->service());

        $this->assertSame(BookingStatus::Failed, $booking->fresh()->status);
    }

    /** A dead worker leaves `processing` lying: nothing is working on it, so say so. */
    public function test_a_dead_job_marks_a_processing_booking_failed(): void
    {
        $booking = $this->booking();
        (new FulfilBookingJob($booking->id))->failed(new \RuntimeException('worker died'));

        $this->assertSame(BookingStatus::Failed, $booking->fresh()->status);
    }

    /** But `booked` holds a real PNR — that needs a person, not a status change. */
    public function test_a_dead_job_leaves_a_held_pnr_alone(): void
    {
        $booking = $this->booking(['status' => BookingStatus::Booked, 'pnr' => 'QWER12']);
        (new FulfilBookingJob($booking->id))->failed(new \RuntimeException('worker died'));

        $this->assertSame(BookingStatus::Booked, $booking->fresh()->status);
    }
}
