<?php

namespace App\Services\Booking\Concerns;

use App\Exceptions\WalletException;
use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\Exceptions\BookingException;
use Illuminate\Support\Str;

/**
 * Taking money from an agency, and naming the booking that took it.
 *
 * Shared by the flight and hotel booking services because it must behave identically
 * for both: the wallet does not care what was sold, and two copies of a debit are two
 * places for the rules to drift apart.
 *
 * Expects a `$wallets` WalletService on the using class.
 */
trait ChargesWallet
{
    /**
     * Debit the booker's agency for a booking.
     *
     * A booker with no agency (platform staff) and a zero total are both no-ops rather
     * than errors — there is simply nothing to take.
     */
    protected function chargeWallet(Booking $booking, User $user, string $total): void
    {
        if ($user->agency === null || bccomp($total, '0', 2) <= 0) {
            return;
        }

        try {
            $this->wallets->debit(
                $this->wallets->for($user->agency),
                $total,
                $user,
                $booking,
                "Booking {$booking->reference}",
            );
        } catch (WalletException $e) {
            throw new BookingException($e->getMessage());
        }
    }

    /**
     * Our own booking reference, unique across every product.
     *
     * On the hotel side this is also what goes to TBO as `BookingReferenceId`, the key
     * a timed-out Book is reconciled by — so it has to exist before the call, and it
     * has to be ours rather than anything the supplier issues.
     */
    protected function reference(): string
    {
        return 'MT-'.strtoupper(Str::random(8));
    }
}
