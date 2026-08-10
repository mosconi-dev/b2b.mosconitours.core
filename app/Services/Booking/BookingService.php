<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Exceptions\WalletException;
use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\DTO\Passenger;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\TboAir\DTO\SelectionInput;
use App\Services\TboAir\TboAirService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the booking lifecycle: creates a durable, retry-safe record from a fresh
 * FareQuote and guards every state transition. No TBO write (Book/Ticket) happens
 * here — that lands in a later phase and hangs off this record.
 */
class BookingService
{
    public function __construct(
        private readonly TboAirService $tbo,
        private readonly WalletService $wallets,
    ) {}

    /** Statuses that end a booking without travel, so the charge goes back. */
    private const REFUNDING = [BookingStatus::Failed, BookingStatus::Cancelled, BookingStatus::Refunded];

    /**
     * Persist a `quoted` booking. Re-prices via FareQuote (a read) so the snapshot
     * is authoritative — never trusts a client-supplied price. The booking is stamped
     * with the current environment, which is then immutable.
     *
     * @param  array<int, Passenger>  $passengers
     * @param  array<string, mixed>  $contact
     */
    public function createFromQuote(User $user, SelectionInput $selection, array $passengers, array $contact): Booking
    {
        $quote = $this->tbo->fareQuote($selection); // read; throws TboAirException on an expired fare

        if ($quote->isPassportMandatory) {
            foreach ($passengers as $passenger) {
                if (! $passenger->hasPassport()) {
                    throw new BookingException('Passport number and expiry are required for every passenger on this fare.');
                }
            }
        }

        [$pax, $ancillaryTotal] = $this->applyAncillaries($selection, $passengers);

        $total = number_format((float) $quote->price['offeredFare'] + $ancillaryTotal, 2, '.', '');

        // The TBO reads above are deliberately outside the transaction — only the
        // booking row and its wallet charge go in it, so an insufficient balance
        // rolls the booking back rather than leaving one nobody paid for.
        return DB::transaction(function () use ($user, $selection, $quote, $pax, $ancillaryTotal, $total, $contact): Booking {
            $booking = Booking::create([
                'reference' => $this->reference(),
                'user_id' => $user->getKey(),
                // Stamped once, like the environment: if the booker later transfers to
                // another agency, this booking stays with the agency that made it.
                'agency_id' => $user->agency_id,
                'environment' => $this->tbo->environment(),
                'status' => BookingStatus::Quoted,
                'trace_id' => $selection->traceId,
                'result_index' => $selection->resultIndex,
                'is_lcc' => $quote->isLcc,
                'currency' => $quote->price['currency'],
                'ancillary_total' => $ancillaryTotal,
                'total_amount' => $total,
                'quote' => $quote->toArray(),
                // The lossy UI snapshot above is not enough to build a Book payload —
                // keep the response TBO actually sent. See the quote_raw migration.
                'quote_raw' => $quote->raw,
                'pax' => $pax,
                'contact' => $contact,
            ]);

            $this->chargeWallet($booking, $user, $total);

            return $booking;
        });
    }

    /**
     * Take the booking's cost out of the agency's wallet.
     *
     * Platform staff have no agency and therefore no wallet, so their bookings are
     * not charged — they are the operator, not a customer of the balance.
     *
     * An insufficient balance is re-thrown as a BookingException so it travels the
     * same path as every other booking failure (including the JSON response the
     * wizard expects) instead of rendering as an unrelated wallet error.
     */
    private function chargeWallet(Booking $booking, User $user, string $total): void
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
     * Resolve each passenger's selected baggage/meal against a fresh GetSSR (so the
     * price is authoritative, never client-supplied), returning the stored pax rows and
     * the total ancillary spend. Infants may not carry extra baggage.
     *
     * @param  array<int, Passenger>  $passengers
     * @return array{0: array<int, array<string, mixed>>, 1: float}
     */
    private function applyAncillaries(SelectionInput $selection, array $passengers): array
    {
        $wantsSsr = array_filter($passengers, fn (Passenger $p): bool => filled($p->baggage) || filled($p->meal));

        foreach ($wantsSsr as $passenger) {
            if ($passenger->isInfant() && filled($passenger->baggage)) {
                throw new BookingException('Extra baggage is not available for infant passengers.');
            }
        }

        $ssr = $wantsSsr === [] ? null : $this->tbo->ssr($selection); // fetched once, authoritative
        $total = 0.0;

        $pax = array_map(function (Passenger $p) use ($ssr, &$total): array {
            $entry = $p->toArray();
            $entry['ssr'] = ['baggage' => null, 'meal' => null];

            if ($ssr === null) {
                return $entry;
            }

            if (filled($p->baggage) && $bag = $ssr->baggage($p->baggage)) {
                $entry['ssr']['baggage'] = $bag;
                $total += (float) $bag['price'];
            }

            if (filled($p->meal) && $meal = $ssr->meal($p->meal)) {
                $entry['ssr']['meal'] = $meal;
                $total += (float) $meal['price'];
            }

            return $entry;
        }, $passengers);

        return [$pax, $total];
    }

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

    private function reference(): string
    {
        return 'MT-'.strtoupper(Str::random(8));
    }
}
