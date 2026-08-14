<?php

namespace App\Services\Booking\Concerns;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Booking\Exceptions\BookingException;
use Illuminate\Support\Facades\DB;

/**
 * Moving a booking through its lifecycle, and giving the money back when it ends
 * without travel.
 *
 * Shared by both products because the state machine is: BookingStatus already keeps the
 * flight and hotel branches from crossing, and the refund rule — an agency should not
 * be left paying for a booking that failed or was cancelled — is the same whichever was
 * sold. Two copies of that would be two places for it to drift.
 *
 * Expects a `$wallets` WalletService on the using class.
 */
trait TransitionsBooking
{
    /** Statuses that end a booking without travel, so the charge goes back. */
    private const REFUNDING = [BookingStatus::Failed, BookingStatus::Cancelled, BookingStatus::Refunded];

    /**
     * Move a booking to a new status, refusing illegal transitions.
     *
     * @param  array<string, mixed>  $attributes  extra fields to persist (pnr, booking_id, …)
     */
    public function transitionTo(Booking $booking, BookingStatus $to, array $attributes = []): Booking
    {
        if (! $booking->status->canTransitionTo($to)) {
            throw new BookingException("Cannot move a {$booking->status->value} booking to {$to->value}.");
        }

        return DB::transaction(function () use ($booking, $to, $attributes): Booking {
            $booking->fill($attributes);
            $booking->status = $to;
            $booking->save();

            if (in_array($to, self::REFUNDING, true)) {
                $this->refundWallet($booking);
            }

            return $booking;
        });
    }

    /**
     * Give the charge back when a booking ends without travel.
     *
     * Refunds the exact amount that was taken, read from the original ledger entry
     * rather than from the booking — the two cannot drift, and a booking that was
     * never charged (no agency, or zero total) has nothing to give back.
     *
     * Guarded against refunding twice: Ticketed → Cancelled → Refunded walks through
     * two refunding statuses, and only the first may move money.
     */
    private function refundWallet(Booking $booking): void
    {
        if ($booking->agency === null || $booking->wasRefundedToWallet()) {
            return;
        }

        $charge = $booking->walletCharge();

        if ($charge === null) {
            return;
        }

        $this->wallets->credit(
            $this->wallets->for($booking->agency),
            (string) $charge->amount,
            null,
            $booking,
            "Refund for booking {$booking->reference}",
        );
    }
}
