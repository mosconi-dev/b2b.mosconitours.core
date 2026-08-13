<?php

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\TboHotel\HotelBookingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Send one booking to TBO, off the request.
 *
 * A Book measured 8.5 seconds against the test environment and is allowed 120 by the
 * spec, so it does not belong in an HTTP request an agent is waiting on. They get a
 * `processing` booking straight away and the page follows it.
 *
 * **One attempt, never retried.** Book is not idempotent: a retry after a timeout can
 * buy the same room twice. An unanswered Book is settled by ReconcileHotelBooking,
 * which reads the reference back — asking again is the one thing that must not happen.
 */
class BookHotelJob implements ShouldQueue
{
    use Queueable;

    /** See the class note: this call cannot be safely repeated. */
    public int $tries = 1;

    /** The spec's own ceiling for Book, plus room to record the outcome. */
    public int $timeout = 150;

    public function __construct(public readonly int $bookingId) {}

    public function handle(HotelBookingService $bookings): void
    {
        $booking = Booking::with('hotel')->find($this->bookingId);

        if ($booking === null) {
            return;
        }

        // A second delivery must never send a second Book. Quoted is the only state
        // this job may act on; Processing means one is already in flight or awaiting
        // reconciliation, and anything else has reached an ending.
        if ($booking->status !== BookingStatus::Quoted) {
            Log::info('BookHotelJob skipped: booking is not awaiting a Book', [
                'booking' => $booking->reference,
                'status' => $booking->status->value,
            ]);

            return;
        }

        try {
            $bookings->book($booking);
        } catch (BookingException $e) {
            // The booking has already been marked failed and refunded by the service;
            // this is the record of why, for the page and for support.
            Log::warning('Hotel booking refused by TBO', [
                'booking' => $booking->reference,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
