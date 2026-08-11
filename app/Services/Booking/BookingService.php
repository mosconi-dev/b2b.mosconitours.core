<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\TboBookingStatus;
use App\Exceptions\WalletException;
use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\DTO\Passenger;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\TboAir\DTO\BookingResult;
use App\Services\TboAir\DTO\SelectionInput;
use App\Services\TboAir\Exceptions\TboAirException;
use App\Services\TboAir\TboAirService;
use App\Services\Wallet\WalletService;
use App\Support\Countries;
use Illuminate\Support\Facades\Cache;
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
     * @param  array<int, int|null>  $seats  per-segment availability from the search
     * @param  int|null  $resultType  TBO's ResultRecommendationType from the search
     */
    public function createFromQuote(User $user, SelectionInput $selection, array $passengers, array $contact, array $seats = [], ?int $resultType = null): Booking
    {
        $quote = $this->tbo->fareQuote($selection); // read; throws TboAirException on an expired fare

        // An international itinerary always needs a passport; a domestic one needs a
        // government ID whenever TBO's flags ask for a document at all. The route
        // decides *which* document, TBO's flags decide *whether* — see TboBookPayload.
        if ($quote->isPassportMandatory || ! $quote->isDomestic) {
            $document = $quote->isDomestic ? 'ID number and expiry are' : 'Passport number and expiry are';

            foreach ($passengers as $passenger) {
                if (! $passenger->hasDocument()) {
                    throw new BookingException("{$document} required for every passenger on this fare.");
                }
            }
        }

        $passengers = $this->withLeadPax($passengers);

        [$pax, $ancillaryTotal] = $this->applyAncillaries($selection, $passengers);
        $pax = $this->applyContact($pax, $contact);

        $total = number_format((float) $quote->price['offeredFare'] + $ancillaryTotal, 2, '.', '');

        // The TBO reads above are deliberately outside the transaction — only the
        // booking row and its wallet charge go in it, so an insufficient balance
        // rolls the booking back rather than leaving one nobody paid for.
        return DB::transaction(function () use ($user, $selection, $quote, $pax, $ancillaryTotal, $total, $contact, $seats, $resultType): Booking {
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
                // Search-only, and Book needs it: TBO drops NoOfSeatAvailable from the
                // FareQuote response. Kept beside quote_raw rather than merged into it,
                // so that column stays a verbatim copy of what TBO sent.
                'seats_available' => $seats,
                'result_type' => $resultType,
                'pax' => $pax,
                'contact' => $contact,
            ]);

            $this->chargeWallet($booking, $user, $total);

            return $booking;
        });
    }

    /**
     * Hold the PNR for a non-LCC booking. Creates a reservation; spends nothing.
     *
     * LCC fares have no Book step at all — Ticket books and issues in one — so calling
     * this on one is a programming error, not a supplier failure.
     */
    public function book(Booking $booking, ?string $userAgent = null): Booking
    {
        if ($booking->is_lcc) {
            throw new BookingException('Low-cost fares are booked and ticketed in one step — use issue().');
        }

        return $this->withBookingLock($booking, function (Booking $booking) use ($userAgent): Booking {
            $this->guardEnvironment($booking);
            $this->guardStatus($booking, [BookingStatus::Quoted], 'booked');

            $result = $this->tbo->book($booking, $userAgent);

            // Before anything else can fail: if TBO made a PNR, we must own that fact.
            $this->rememberSupplierIds($booking, $result);

            $status = $this->resolve($booking, $result);

            if ($status === null) {
                throw BookingException::unresolved($booking, $result->pnr);
            }

            // Book maps its own outcome: TBO's `Successful` here means a PNR was held,
            // not that anything was issued. Reusing the ticketing mapping would mark
            // this booking Ticketed and skip the step that actually spends money.
            if ($status->isFailure()) {
                $this->transitionTo($booking, BookingStatus::Failed);
                throw $this->refused($booking, $result, 'The airline did not hold this booking.');
            }

            return $this->transitionTo($booking, BookingStatus::Booked);
        });
    }

    /**
     * Issue the ticket. **This is the step that spends money.**
     *
     * LCC goes straight from `quoted` (Ticket books and issues together); non-LCC must
     * already hold a PNR from book().
     */
    public function issue(Booking $booking, ?string $userAgent = null): Booking
    {
        return $this->withBookingLock($booking, function (Booking $booking) use ($userAgent): Booking {
            $this->guardEnvironment($booking);

            $from = $booking->is_lcc ? [BookingStatus::Quoted] : [BookingStatus::Booked];
            $this->guardStatus($booking, $from, 'ticketed');

            if (! $booking->is_lcc && ! filled($booking->pnr)) {
                throw new BookingException("Booking {$booking->reference} has no PNR to ticket.");
            }

            $result = $this->tbo->ticket($booking, $booking->pnr, $userAgent);
            $this->rememberSupplierIds($booking, $result);

            $status = $this->resolve($booking, $result);
            $mapped = $status?->toBookingStatus();

            if ($mapped === null) {
                throw BookingException::unresolved($booking, $result->pnr);
            }

            // Failed is a real answer, not an exception in the supplier's eyes — but it
            // must not travel back as though the ticket exists. Move the booking (which
            // refunds the agency), then say why in TBO's own words.
            if ($mapped === BookingStatus::Failed) {
                $this->transitionTo($booking, BookingStatus::Failed);
                throw $this->refused($booking, $result, 'The airline did not issue this ticket.');
            }

            return $this->transitionTo($booking, $mapped);
        });
    }

    /**
     * Establish TBO's authoritative status for a response, reading GetBookingDetails
     * when the write call will not say.
     *
     * Returns TBO's own code rather than one of ours, because the same code means
     * different things per call — `Successful` is "held" from Book and "issued" from
     * Ticket. Each caller maps it.
     *
     * Returns null when even that is unresolved — the caller must **not** guess. This
     * is the case the live system gets wrong: marking an unknown outcome as failed
     * refunds the agency while a live PNR may exist, and retrying it risks selling the
     * seat twice.
     */
    private function resolve(Booking $booking, BookingResult $result): ?TboBookingStatus
    {
        if (! $result->needsReconciliation()) {
            return $result->status;
        }

        // Nothing to reconcile against: no PNR means TBO created nothing.
        if (! $result->hasPnr()) {
            return TboBookingStatus::Failed;
        }

        try {
            $authoritative = $this->tbo->bookingDetails($result->pnr);
        } catch (TboAirException) {
            return null; // could not read it — unresolved, never assumed
        }

        $this->rememberSupplierIds($booking, $authoritative);

        // Ticket numbers outrank every status field. On a booking we ticketed
        // successfully, GetBookingDetails reported Ticketed=false and a top-level
        // Status of 5 (BookedOther) while carrying real ticket numbers — believing the
        // status would have recorded a paid, issued booking as merely held.
        if ($authoritative->isTicketed()) {
            return TboBookingStatus::Successful;
        }

        return $authoritative->needsReconciliation() ? null : $authoritative->status;
    }

    /**
     * Persist the PNR and TBO booking id the moment we learn them.
     *
     * Written outside the status transition and before any further call: a PNR we
     * failed to record is a live reservation nobody can find, which is worse than a
     * booking in the wrong state. ReleasePNR later needs it too.
     */
    private function rememberSupplierIds(Booking $booking, BookingResult $result): void
    {
        $attributes = array_filter([
            'pnr' => $result->pnr,
            'booking_id' => $result->bookingId,
        ], fn (?string $value): bool => filled($value));

        if ($attributes !== []) {
            $booking->forceFill($attributes)->save();
        }

        $this->rememberTickets($booking, $result);
    }

    /**
     * Write each issued ticket number onto its passenger row.
     *
     * The ticket number is the artefact the traveller and the airline both work from,
     * and ReleasePNR/Void will need it. TBO only returns it on GetBookingDetails, so
     * it is stored the moment a read produces one.
     *
     * Matched on **name**, falling back to position. TBO's `PaxId` is its own internal
     * identifier (`99470`, not `1`), so it says nothing about which of our rows a
     * ticket belongs to — and attaching one passenger's ticket to another is not a
     * mistake anyone would catch later.
     */
    private function rememberTickets(Booking $booking, BookingResult $result): void
    {
        $tickets = $result->tickets();

        if ($tickets === []) {
            return;
        }

        $pax = (array) ($booking->pax ?? []);
        $changed = false;

        $name = fn (array $row): string => strtolower(trim(
            ($row['firstName'] ?? '').' '.($row['lastName'] ?? '')
        ));

        foreach (array_values($tickets) as $i => $ticket) {
            $index = null;

            foreach ($pax as $j => $row) {
                if ($name($row) !== '' && $name($row) === strtolower(trim($ticket['name']))) {
                    $index = $j;
                    break;
                }
            }

            // Same order we sent them in, so position is a sound fallback.
            $index ??= $i;

            if (! isset($pax[$index]) || ($pax[$index]['ticketNumber'] ?? null) === $ticket['number']) {
                continue;
            }

            $pax[$index]['ticketNumber'] = $ticket['number'];
            $pax[$index]['ticketIssuedAt'] = $ticket['issuedAt'];
            $changed = true;
        }

        if ($changed) {
            $booking->forceFill(['pax' => $pax])->save();
        }
    }

    /**
     * Turn a supplier refusal into something an agent can act on.
     *
     * TBO's own message is the whole point: an insufficient agency balance, a fare
     * that has gone, a rejected passenger detail — all of it arrives here, on the
     * response, which is why there is no pre-flight funds check. Pass it through
     * verbatim and only fall back when TBO said nothing.
     */
    private function refused(Booking $booking, BookingResult $result, string $fallback): BookingException
    {
        $reason = $result->message();

        return new BookingException(
            "Booking {$booking->reference}: ".($reason === null ? $fallback : $reason)
        );
    }

    /**
     * One environment end to end. A booking quoted on test must never be ticketed
     * against live, whatever the current user's environment happens to be.
     */
    private function guardEnvironment(Booking $booking): void
    {
        if ($booking->environment !== $this->tbo->environment()) {
            throw new BookingException(
                "Booking {$booking->reference} was quoted on {$booking->environment} and cannot be processed on {$this->tbo->environment()}."
            );
        }
    }

    /**
     * @param  array<int, BookingStatus>  $allowed
     */
    private function guardStatus(Booking $booking, array $allowed, string $action): void
    {
        if (! in_array($booking->status, $allowed, true)) {
            throw new BookingException(
                "Booking {$booking->reference} is {$booking->status->value} and cannot be {$action}."
            );
        }
    }

    /**
     * Serialise every write against one booking, and re-read it under the lock.
     *
     * Two guards, because this is money and they fail differently: the cache lock stops
     * concurrent workers racing (a double-clicked button, a retried job), and the
     * status re-read inside it means the second caller sees what the first actually
     * did rather than the row it loaded beforehand.
     *
     * The supplier call is deliberately **not** inside a database transaction — it can
     * take a minute, and a row lock held across it would block the whole booking. The
     * writes it triggers are individually atomic instead.
     *
     * @param  callable(Booking): Booking  $work
     */
    private function withBookingLock(Booking $booking, callable $work): Booking
    {
        $lock = Cache::lock("booking:{$booking->getKey()}:write", 120);

        if (! $lock->get()) {
            throw new BookingException("Booking {$booking->reference} is already being processed.");
        }

        try {
            return $work($booking->fresh());
        } finally {
            $lock->release();
        }
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
     * Guarantee exactly one lead passenger.
     *
     * TBO wants `IsLeadPax` on the Book payload and expects precisely one. The lead
     * must be an adult — the lead is who the airline contacts, and a child cannot hold
     * that role — so a flag on a child or infant is not honoured. When nothing usable
     * is flagged the first adult takes it, which is what an agent means anyway.
     *
     * @param  array<int, Passenger>  $passengers
     * @return array<int, Passenger>
     */
    private function withLeadPax(array $passengers): array
    {
        $lead = null;

        foreach ($passengers as $i => $passenger) {
            if ($passenger->isLeadPax && $passenger->isAdult()) {
                $lead = $i;
                break;
            }
        }

        if ($lead === null) {
            foreach ($passengers as $i => $passenger) {
                if ($passenger->isAdult()) {
                    $lead = $i;
                    break;
                }
            }
        }

        if ($lead === null) {
            throw new BookingException('A booking needs at least one adult passenger.');
        }

        return array_map(
            fn (Passenger $p, int $i): Passenger => $p->withLead($i === $lead),
            $passengers,
            array_keys($passengers),
        );
    }

    /**
     * Fan the booking's shared contact details onto every passenger row.
     *
     * TBO's Book method wants an address, city, country, mobile and email on *each*
     * passenger, but none of that varies per passenger — it is one contact block. It
     * is copied in at persistence time so every stored row is Book-ready, and the
     * country name is derived from the code rather than collected, so the two cannot
     * disagree.
     *
     * @param  array<int, array<string, mixed>>  $pax
     * @param  array<string, mixed>  $contact
     * @return array<int, array<string, mixed>>
     */
    private function applyContact(array $pax, array $contact): array
    {
        $countryCode = strtoupper(trim((string) ($contact['countryCode'] ?? '')));

        $shared = [
            'email' => $contact['email'] ?? null,
            'mobile' => $contact['phone'] ?? null,
            'mobileCountryCode' => $contact['mobileCountryCode'] ?? null,
            'addressLine1' => $contact['addressLine1'] ?? null,
            'addressLine2' => $contact['addressLine2'] ?? null,
            'city' => $contact['city'] ?? null,
            'countryCode' => $countryCode ?: null,
            'countryName' => $countryCode === '' ? null : Countries::name($countryCode),
        ];

        // Union, not merge: anything already on the row wins over the shared block.
        return array_map(fn (array $row): array => $row + $shared, $pax);
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
