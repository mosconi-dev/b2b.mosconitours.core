<?php

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\TboHotel\DTO\BookingDetailResult;
use App\Services\TboHotel\Exceptions\TboHotelException;
use App\Services\TboHotel\HotelBookingService;
use App\Services\TboHotel\TboHotelService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Find out what actually happened to a Book we did not get an answer to.
 *
 * §10, verbatim: *"In case of timeout/failure/http/network related error in book
 * response then it is mandatory to call the BookingDetail method by using
 * BookingReferenceId after 120 seconds of book response."*
 *
 * This is the job that stops a timeout costing an agency a room. A Book that dies on
 * the wire may well have succeeded at TBO, so the booking is left `processing` and this
 * reads the reference back until the supplier commits to an answer.
 *
 * **It never re-Books.** The reference has been spent; sending it again could buy the
 * same room twice, and a duplicate reservation is worse than a late one.
 *
 * A booking TBO will not account for after every attempt is left `processing` and
 * raised, because at that point it is a support question and not a retry.
 */
class ReconcileHotelBooking implements ShouldQueue
{
    use Queueable;

    /** Own retry ladder, so a re-queue is a decision rather than a framework default. */
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

        // Anything settled has already been answered — by an earlier run of this job,
        // or by the Book that eventually came back. Nothing to do.
        if ($booking->status !== BookingStatus::Processing) {
            return;
        }

        // Reading test data against a live account, or the reverse, would answer the
        // wrong question entirely.
        if ($booking->environment !== $tbo->environment()) {
            Log::warning('Hotel reconcile skipped: wrong environment', [
                'booking' => $booking->reference,
                'quoted_on' => $booking->environment,
                'running_on' => $tbo->environment(),
            ]);

            return;
        }

        try {
            $detail = BookingDetailResult::fromResponse($tbo->bookingDetail($booking->reference));
        } catch (TboHotelException $e) {
            // Could not ask. That is not an answer either, so try again later.
            $this->again($booking, 'BookingDetail failed: '.$e->getMessage());

            return;
        }

        $this->resolve($booking, $detail, $bookings);
    }

    /**
     * Turn TBO's account of the booking into our status.
     *
     * Only endings are acted on. Anything else — an empty status, a state we do not
     * recognise, a cancellation still travelling at their end — is treated as "not yet
     * known" and asked again, because acting on a half-answer is how a real reservation
     * gets refunded or a failed one gets charged.
     *
     * The reading itself lives in BookingDetailResult, shared with the refresh: two
     * interpretations of one payload would settle their disagreements by whichever ran
     * last.
     */
    private function resolve(Booking $booking, BookingDetailResult $detail, HotelBookingService $bookings): void
    {
        if ($detail->isConfirmed()) {
            $bookings->confirm(
                $booking,
                (string) $detail->confirmationNumber,
                $detail->hotelConfirmationNumber,
                $detail->invoiceNumber,
            );

            Log::info('Hotel booking reconciled as confirmed', [
                'booking' => $booking->reference,
                'confirmation' => $detail->confirmationNumber,
                'attempt' => $this->attempt,
            ]);

            return;
        }

        if ($detail->isCancelled() || $detail->isFailed()) {
            // Failed refunds the wallet, which is right: TBO is telling us no room was
            // ever held, so the agency is not paying for one.
            $bookings->fail($booking, "TBO reports the booking as {$detail->status}.");

            Log::warning('Hotel booking reconciled as failed', [
                'booking' => $booking->reference,
                'reported' => $detail->status,
                'attempt' => $this->attempt,
            ]);

            return;
        }

        $this->again($booking, "BookingDetail reported '{$detail->status}' with confirmation '{$detail->confirmationNumber}'.");
    }

    /**
     * Ask again later, backing off, until it stops being a question we can answer.
     *
     * The booking stays `processing` throughout. That is deliberate: it is neither
     * confirmed nor failed, and pretending otherwise to tidy a list is how an agency
     * gets told a room exists that does not.
     */
    private function again(Booking $booking, string $reason): void
    {
        $cap = (int) config('tbohotel.reconcile_attempts', 8);

        if ($this->attempt >= $cap) {
            Log::error('Hotel booking could not be reconciled; needs a human', [
                'booking' => $booking->reference,
                'attempts' => $this->attempt,
                'reason' => $reason,
            ]);

            return;
        }

        $delay = (int) config('tbohotel.reconcile_delay', 120) * $this->attempt;

        Log::info('Hotel booking still unresolved; will ask again', [
            'booking' => $booking->reference,
            'attempt' => $this->attempt,
            'next_in_seconds' => $delay,
            'reason' => $reason,
        ]);

        self::dispatch($this->bookingId, $this->attempt + 1)->delay(now()->addSeconds($delay));
    }
}
