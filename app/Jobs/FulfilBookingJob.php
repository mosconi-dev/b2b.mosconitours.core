<?php

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Booking\BookingService;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\TboAir\Exceptions\TboAirException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Runs Book → Ticket for one booking, off the request.
 *
 * This has to be a job rather than a long controller action because of how slow the
 * supplier is: against the test environment a single Ticket took **50.7 seconds** and
 * Book up to 16.5, so a non-LCC chain can outlast any sane HTTP timeout. The agent gets
 * a `processing` booking immediately and the page follows it to its ending.
 *
 * Deliberately **not** retried. Book and Ticket are not idempotent — a retry after a
 * timeout can buy a second ticket for the same passengers — so a failure here is
 * recorded and left for a human. `reconcile()` is how an ambiguous one gets settled,
 * not another attempt.
 */
class FulfilBookingJob implements ShouldQueue
{
    use Queueable;

    /** One attempt. See the class note: these calls cannot be safely repeated. */
    public int $tries = 1;

    /** Long enough for Book + Ticket at their observed worst, plus room. */
    public int $timeout = 180;

    public function __construct(
        public readonly int $bookingId,
        public readonly ?string $userAgent = null,
    ) {}

    public function handle(BookingService $bookings): void
    {
        $booking = Booking::find($this->bookingId);

        if ($booking === null) {
            return;
        }

        // Anything not still in flight has already reached an ending — a second
        // delivery of this job must never re-issue a ticket that exists.
        if (! $booking->status->isInFlight()) {
            Log::info('FulfilBookingJob skipped: booking already settled', [
                'booking' => $booking->reference,
                'status' => $booking->status->value,
            ]);

            return;
        }

        try {
            $bookings->fulfil($booking, $this->userAgent);
        } catch (BookingException|TboAirException $e) {
            // The booking already carries the outcome: fulfil() transitions it to
            // Failed on a refusal, and leaves it where it stands when the supplier's
            // answer was ambiguous. Nothing to re-raise — re-raising would only queue
            // a retry we have just said is unsafe.
            Log::warning('FulfilBookingJob did not complete', [
                'booking' => $booking->reference,
                'status' => $booking->fresh()?->status->value,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The job itself died — a fatal error, a timeout, a lost worker.
     *
     * A booking left sitting in `processing` looks like it is still going when nothing
     * is working on it, so it is marked failed. `booked` is left alone on purpose: that
     * one holds a real PNR and needs a person, not a status change.
     */
    public function failed(?\Throwable $e): void
    {
        $booking = Booking::find($this->bookingId);

        if ($booking?->status !== BookingStatus::Processing) {
            return;
        }

        $booking->update(['status' => BookingStatus::Failed]);

        Log::error('FulfilBookingJob failed outright', [
            'booking' => $booking->reference,
            'reason' => $e?->getMessage(),
        ]);
    }
}
