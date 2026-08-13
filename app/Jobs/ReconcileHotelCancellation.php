<?php

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\TboHotel\Exceptions\TboHotelException;
use App\Services\TboHotel\HotelBookingService;
use App\Services\TboHotel\TboHotelService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Find out whether a Cancel we got no answer to actually released the room.
 *
 * The mirror of ReconcileHotelBooking, and needed for the mirror reason. A Cancel that
 * dies on the wire may well have succeeded, so the booking waits in `cancelling` — an
 * honest state, neither cancelled nor safely still standing — until TBO commits.
 *
 * **It never cancels again.** A second Cancel against a booking TBO has already
 * released answers 479, which is indistinguishable from a genuine refusal; we would
 * then tell an agency their cancellation failed and keep money we should have returned.
 * Reading is the only safe question.
 */
class ReconcileHotelCancellation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $bookingId,
        public readonly int $attempt = 1,
    ) {}

    public function handle(TboHotelService $tbo, HotelBookingService $bookings): void
    {
        $booking = Booking::with('hotel')->find($this->bookingId);

        if ($booking === null) {
            return;
        }

        // Settled already — by a later refresh, or by an agent pressing the button.
        if ($booking->status !== BookingStatus::Cancelling) {
            return;
        }

        if ($booking->environment !== $tbo->environment()) {
            Log::warning('Hotel cancellation reconcile skipped: wrong environment', [
                'booking' => $booking->reference,
                'made_on' => $booking->environment,
                'running_on' => $tbo->environment(),
            ]);

            return;
        }

        try {
            // refresh() is the whole of the work: it reads TBO's account and moves our
            // status to match, including cancelling → cancelled.
            $booking = $bookings->refresh($booking);
        } catch (TboHotelException|BookingException $e) {
            $this->again($booking, $e->getMessage());

            return;
        }

        if ($booking->status === BookingStatus::Cancelling) {
            $this->again($booking, "TBO still reports '{$booking->hotel?->supplier_status}'.");

            return;
        }

        Log::info('Hotel cancellation reconciled', [
            'booking' => $booking->reference,
            'status' => $booking->status->value,
            'attempt' => $this->attempt,
        ]);
    }

    /**
     * A cancellation TBO has not committed to is money we are holding on both sides.
     * Worth asking about repeatedly, and worth a human when the asking runs out.
     */
    private function again(Booking $booking, string $reason): void
    {
        $cap = (int) config('tbohotel.reconcile_attempts', 8);

        if ($this->attempt >= $cap) {
            Log::error('Hotel cancellation could not be reconciled; needs a human', [
                'booking' => $booking->reference,
                'attempts' => $this->attempt,
                'reason' => $reason,
            ]);

            return;
        }

        $delay = (int) config('tbohotel.reconcile_delay', 120) * $this->attempt;

        Log::info('Hotel cancellation still unresolved; will ask again', [
            'booking' => $booking->reference,
            'attempt' => $this->attempt,
            'next_in_seconds' => $delay,
            'reason' => $reason,
        ]);

        self::dispatch($this->bookingId, $this->attempt + 1)->delay(now()->addSeconds($delay));
    }
}
