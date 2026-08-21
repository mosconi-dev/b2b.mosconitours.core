<?php

namespace App\Services\TboHotel;

use App\Enums\BookingProduct;
use App\Enums\BookingStatus;
use App\Enums\Supplier;
use App\Jobs\ReconcileHotelBooking;
use App\Jobs\ReconcileHotelCancellation;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\User;
use App\Services\Booking\Concerns\ChargesWallet;
use App\Services\Booking\Concerns\RecordsPriceLayers;
use App\Services\Booking\Concerns\TransitionsBooking;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\Pricing\Money;
use App\Services\Pricing\PriceBreakdown;
use App\Services\Pricing\PricingContextFactory;
use App\Services\Pricing\PricingEngine;
use App\Services\TboHotel\DTO\BookingDetailResult;
use App\Services\TboHotel\DTO\Guest;
use App\Services\TboHotel\DTO\PreBookResult;
use App\Services\TboHotel\DTO\SearchInput;
use App\Services\TboHotel\Exceptions\TboHotelException;
use App\Services\Wallet\WalletService;
use App\Support\TravelScopeResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turning a chosen rate into a durable, paid-for booking — with nothing yet committed
 * at TBO.
 *
 * The counterpart of BookingService for hotels, and deliberately a separate class: the
 * two share the booking spine and the wallet, and almost nothing else. There is no
 * PNR, no ticket, no hold, and one call vouchers the stay.
 */
class HotelBookingService
{
    use ChargesWallet, RecordsPriceLayers, TransitionsBooking;

    public function __construct(
        private readonly TboHotelService $tbo,
        private readonly WalletService $wallets,
        private readonly PricingEngine $pricing,
        private readonly PricingContextFactory $contexts,
    ) {}

    /**
     * Persist a `quoted` hotel booking and take the money.
     *
     * Re-prices through PreBook first, always: the price the browser sends back is
     * what the agent was *shown*, which is evidence about the gate and never a source
     * of truth about what to charge. §18 makes PreBook's policy final, so its response
     * — not the search's — is what gets stored.
     *
     * @param  array<int, Guest>  $guests
     * @param  array{email: string, phone: string}  $contact
     *
     * @throws BookingException when the guests do not match the stay, or the wallet is short
     * @throws TboHotelException when the rate has expired or vanished
     */
    public function createFromQuote(
        User $user,
        SearchInput $search,
        string $bookingCode,
        array $guests,
        array $contact,
        ?float $shownSellFare = null,
        bool $acceptPriceChange = false,
    ): Booking {
        $quote = $this->tbo->preBook($bookingCode);

        $hotel = Hotel::where('code', $quote->hotelCode ?: $search->locationCode)->first();

        // Priced from PreBook's NET with the same engine and rules the rooms page used.
        $price = $this->priceOf($quote, $search, $hotel, $user);

        // A price move between Search and PreBook is normal. It is only an error if
        // nobody agreed to it — booking silently at the new price spends an agency's
        // money on a figure it never saw.
        //
        // BOTH SIDES ARE SELLING PRICES. The browser holds what the agent was shown,
        // which is marked up; comparing that against PreBook's net would fire the gate
        // on every single booking, the markup alone reading as a re-price. Re-running
        // the engine on the fresh net gives a like-for-like figure, so this fires only
        // when TBO actually moved.
        if ($shownSellFare !== null && ! $acceptPriceChange
            && Money::of($shownSellFare)->compare($price->sell->amount) !== 0) {
            throw new BookingException(sprintf(
                'The hotel re-priced this room from %s to %s. Confirm the new price to continue.',
                number_format($shownSellFare, 2),
                $price->sell->amount->formatted(),
            ));
        }

        $guests = $this->guardGuests($guests, $search);

        // PreBook above is a supplier read and stays outside the transaction. Only the
        // rows and the wallet charge go inside, so a short balance rolls the whole
        // booking back rather than leaving one nobody paid for.
        return DB::transaction(function () use ($user, $search, $quote, $guests, $contact, $price, $hotel): Booking {
            $booking = Booking::create([
                'reference' => $this->reference(),
                'product' => BookingProduct::Hotel,
                'supplier' => Supplier::TboHotel,
                'user_id' => $user->getKey(),
                'agency_id' => $user->agency_id,
                'environment' => $this->tbo->environment(),
                'status' => BookingStatus::Quoted,
                'currency' => $quote->currency,
                'net_amount' => (string) $price->net->amount,
                'cost_amount' => (string) $price->cost(),
                'total_amount' => (string) $price->sell->amount,
                'markup_total' => (string) $price->markupTotal(),
                'quote' => $quote->toArray(),
                // The browser-facing snapshot above cannot rebuild a Book payload —
                // keep what TBO actually sent.
                'quote_raw' => $quote->raw,
                'pax' => array_map(fn (Guest $g): array => $g->toArray(), $guests),
                'contact' => $contact,
            ]);

            $booking->hotel()->create($this->detail($quote, $search, $guests, $hotel));

            $this->recordPriceLayers($booking, $price);

            // The COST, not the selling price — the agency's own markup is its margin
            // from its own customer and is not owed to the platform.
            $this->chargeWallet($booking, $user, (string) $price->cost());

            return $booking;
        });
    }

    /**
     * PreBook's net, run through the pricing engine for this booker.
     *
     * Built from the same shape HotelOffer::toArray() produces, so a rule that matched
     * on the rooms page matches identically here — a rule keyed on star rating or city
     * must not quietly stop applying between the page and the booking.
     */
    private function priceOf(PreBookResult $quote, SearchInput $search, ?Hotel $hotel, User $user): PriceBreakdown
    {
        return $this->pricing->quoteOrNet(
            $this->contexts->forHotelRoom(
                [
                    'hotelCode' => $quote->hotelCode ?: $search->locationCode,
                    'currency' => $quote->currency,
                    'countryCode' => $hotel?->country_code,
                    'cityCode' => $hotel?->city_code,
                    'rating' => $hotel?->rating,
                    'scope' => TravelScopeResolver::forCountryCode($hotel?->country_code)->value,
                ],
                [
                    'totalFare' => $quote->totalFare(),
                    'isRefundable' => $quote->room->isRefundable,
                    'mealType' => $quote->room->mealType,
                    'withTransfers' => $quote->room->withTransfers,
                ],
                nights: $search->nights(),
                rooms: $search->roomCount(),
                checkIn: $search->checkIn,
            ),
            $user->agency,
        );
    }

    /**
     * The hotel_bookings row: everything needed to print a voucher and compute a
     * refund without asking TBO again.
     *
     * @param  array<int, Guest>  $guests
     * @return array<string, mixed>
     */
    private function detail(PreBookResult $quote, SearchInput $search, array $guests, ?Hotel $hotel): array
    {
        return [
            'hotel_code' => $quote->hotelCode ?: $search->locationCode,
            'hotel_name' => $hotel?->name ?? 'Hotel '.$quote->hotelCode,
            'city_code' => $hotel?->city_code,
            'country_code' => $hotel?->country_code,
            'address' => $hotel?->address,
            'rating' => $hotel?->rating,
            'check_in' => $search->checkIn,
            'check_out' => $search->checkOut,
            'nights' => $search->nights(),
            'rooms_count' => $search->roomCount(),
            'guest_nationality' => $search->guestNationality,
            'booking_code' => $quote->room->bookingCode,
            'meal_type' => $quote->room->mealType,
            'is_refundable' => $quote->room->isRefundable,
            'with_transfers' => $quote->room->withTransfers,
            'room_names' => $quote->room->names,
            'cancel_policies' => $quote->room->cancelPolicies->toArray(),
            'supplements' => $quote->room->supplements->toArray(),
            'rate_conditions' => $quote->rateConditions,
            'amenities' => $quote->amenities,
        ];
    }

    /**
     * The guests must describe the stay that was priced.
     *
     * TBO prices per room and per occupant, so a booking whose names do not match the
     * searched occupancy is a different booking from the one that was quoted. Catching
     * it here means saying which room is wrong; catching it at Book means a refusal
     * after the wallet has been debited.
     *
     * @param  array<int, Guest>  $guests
     * @return array<int, Guest>
     */
    private function guardGuests(array $guests, SearchInput $search): array
    {
        if ($guests === []) {
            throw new BookingException('Add the guests staying in each room.');
        }

        foreach ($search->rooms as $index => $room) {
            $inRoom = array_values(array_filter($guests, fn (Guest $g): bool => $g->roomIndex === $index));
            $adults = count(array_filter($inRoom, fn (Guest $g): bool => $g->isAdult()));
            $children = count($inRoom) - $adults;

            if ($adults !== $room->adults || $children !== $room->children) {
                throw new BookingException(sprintf(
                    'Room %d was priced for %d adult(s) and %d child(ren) — %d and %d were given.',
                    $index + 1,
                    $room->adults,
                    $room->children,
                    $adults,
                    $children,
                ));
            }
        }

        foreach ($guests as $guest) {
            if ($guest->firstName === '' || $guest->lastName === '') {
                throw new BookingException('Every guest needs a first and last name.');
            }

            if ($guest->roomIndex >= count($search->rooms)) {
                throw new BookingException('A guest was assigned to a room that is not part of this stay.');
            }
        }

        return $this->withLeadGuest($guests);
    }

    /**
     * Exactly one lead guest, an adult, in the first room.
     *
     * The lead is who the hotel asks for at the desk, so a child cannot hold the role
     * and neither can someone sleeping in another room. When nothing usable is flagged
     * the first adult of room one takes it, which is what an agent means anyway.
     *
     * @param  array<int, Guest>  $guests
     * @return array<int, Guest>
     */
    private function withLeadGuest(array $guests): array
    {
        $lead = null;

        foreach ($guests as $i => $guest) {
            if ($guest->isLead && $guest->isAdult() && $guest->roomIndex === 0) {
                $lead = $i;
                break;
            }
        }

        if ($lead === null) {
            foreach ($guests as $i => $guest) {
                if ($guest->isAdult() && $guest->roomIndex === 0) {
                    $lead = $i;
                    break;
                }
            }
        }

        if ($lead === null) {
            throw new BookingException('The first room needs at least one adult to lead the booking.');
        }

        return array_map(
            fn (Guest $g, int $i): Guest => $g->withLead($i === $lead),
            $guests,
            array_keys($guests),
        );
    }

    /**
     * Send the booking to TBO. This is the money step, and the only one.
     *
     * Deliberately outside any database transaction. A supplier call inside one holds
     * a row lock open across the network, and worse, a rollback would erase our record
     * of a reservation TBO had already made.
     *
     * The booking is marked `processing` **before** the call, not after. If this
     * process dies mid-flight the row still says a Book was attempted, which is what
     * makes the ambiguous case recoverable rather than invisible.
     *
     * @throws BookingException on a refusal we can attribute
     */
    public function book(Booking $booking): Booking
    {
        $lock = Cache::lock("booking:{$booking->getKey()}:write", 180);

        if (! $lock->get()) {
            throw new BookingException("Booking {$booking->reference} is already being processed.");
        }

        try {
            return $this->attemptBook($booking->fresh());
        } finally {
            $lock->release();
        }
    }

    private function attemptBook(Booking $booking): Booking
    {
        $this->guardBookable($booking);

        $payload = TboHotelBookPayload::for($booking);

        if ($booking->status !== BookingStatus::Processing) {
            $booking = $this->transitionTo($booking, BookingStatus::Processing);
        }

        // Stamped before the call, not after. If the worker dies mid-request there is
        // a reservation we may own and no answer saying so; the only safe record is one
        // written before the risk was taken. guardBookable() refuses on it from here on.
        $booking->hotel?->forceFill(['book_sent_at' => now()])->save();

        try {
            $response = $this->tbo->bookRaw($payload);
        } catch (TboHotelException $e) {
            return $this->handleBookFailure($booking, $e);
        }

        $confirmation = trim((string) Arr::get($response, 'ConfirmationNumber', ''));

        // TBO said yes and gave us nothing to hold it by. Treat it as unresolved rather
        // than confirmed: a booking we cannot name is one we cannot cancel or void.
        if ($confirmation === '') {
            Log::warning('TBO Hotel booked without a confirmation number', ['booking' => $booking->reference]);

            return $this->reconcileLater($booking, 'Book returned no confirmation number.');
        }

        return $this->confirm($booking, $confirmation);
    }

    /**
     * Record the reservation, then say it is confirmed.
     *
     * In that order, and in one transaction. If the status moved first and the write of
     * the confirmation number failed, we would hold a booking marked Confirmed with no
     * way to name it at the supplier.
     */
    public function confirm(Booking $booking, string $confirmationNumber, ?string $hotelConfirmationNumber = null, ?string $invoiceNumber = null): Booking
    {
        return DB::transaction(function () use ($booking, $confirmationNumber, $hotelConfirmationNumber, $invoiceNumber): Booking {
            $booking->hotel?->forceFill(array_filter([
                'confirmation_number' => $confirmationNumber,
                'hotel_confirmation_number' => $hotelConfirmationNumber,
                'invoice_number' => $invoiceNumber,
            ], fn ($value): bool => filled($value)))->save();

            return $this->transitionTo($booking, BookingStatus::Confirmed, [
                'supplier_reference' => $confirmationNumber,
            ]);
        });
    }

    /**
     * What cancelling this booking would cost right now.
     *
     * Read from the terms stored at PreBook, which §18 makes final for the itinerary.
     * An estimate: TBO's Cancel does not state a charge and only its invoice settles
     * one. Shown to the agent before they commit, and written down afterwards, so a
     * disputed line has a figure and a moment attached to it.
     */
    public function cancellationCharge(Booking $booking, ?CarbonInterface $at = null): float
    {
        $stay = $booking->hotel;

        return CancelPolicySet::fromStored($stay?->cancel_policies)->chargeAt(
            $at ?? now(),
            (float) $booking->total_amount,
            max(1, (int) ($stay?->rooms_count ?? 1)),
        );
    }

    /**
     * Release the room, and settle the money.
     *
     * The charge is computed **before** the call, from the terms in force at the moment
     * the agent asked — not afterwards, when the ladder may have stepped up between the
     * press and the answer. What TBO then gives back is the whole charge, with the
     * cancellation fee posted as its own debit: two lines that read like what happened,
     * rather than one net figure nobody can reconstruct.
     *
     * @throws BookingException when the booking cannot be cancelled, or TBO refuses
     * @throws TboHotelException when TBO cannot be reached
     */
    public function cancel(Booking $booking): Booking
    {
        $lock = Cache::lock("booking:{$booking->getKey()}:write", 180);

        if (! $lock->get()) {
            throw new BookingException("Booking {$booking->reference} is already being processed.");
        }

        try {
            return $this->attemptCancel($booking->fresh());
        } finally {
            $lock->release();
        }
    }

    private function attemptCancel(Booking $booking): Booking
    {
        $this->guardCancellable($booking);

        $charge = $this->cancellationCharge($booking);
        $confirmation = (string) $booking->hotel?->confirmation_number;

        try {
            $this->tbo->cancel($confirmation);
        } catch (TboHotelException $e) {
            return $this->handleCancelFailure($booking, $e);
        }

        return $this->settleCancellation($booking, $charge);
    }

    /**
     * Write the cancellation down and move the money.
     *
     * The refund is the full charge — the state machine does that on the way into
     * Cancelled — and the fee is taken back out immediately after, in that order, so
     * the wallet never dips below what the agency actually has.
     */
    private function settleCancellation(Booking $booking, float $charge): Booking
    {
        return DB::transaction(function () use ($booking, $charge): Booking {
            $booking->hotel?->forceFill([
                'cancellation_charge' => $charge,
                'cancelled_at' => now(),
                // TBO has just told us this, so it counts as a read — and the per-room
                // statuses from the last one are now describing a booking that no
                // longer exists. Dropped rather than left to contradict the line above
                // them; the next check repopulates them from the source.
                'supplier_status' => 'Cancelled',
                'room_statuses' => null,
                'refreshed_at' => now(),
            ])->save();

            $refunded = $booking->walletCharge() !== null;
            $booking = $this->transitionTo($booking, BookingStatus::Cancelled);

            // Only when there was something to refund in the first place. A booker with
            // no agency was never debited, and taking a fee off them would be the first
            // money this booking ever moved.
            if ($charge > 0 && $refunded && $booking->agency !== null) {
                $this->wallets->debit(
                    $this->wallets->for($booking->agency),
                    number_format($charge, 2, '.', ''),
                    null,
                    $booking,
                    "Cancellation charge for booking {$booking->reference} (estimated)",
                );
            }

            Log::info('Hotel booking cancelled', [
                'booking' => $booking->reference,
                'charge' => $charge,
                'refunded' => $refunded,
            ]);

            return $booking->fresh();
        });
    }

    /**
     * A Cancel that failed: refused, or never answered.
     *
     * `479` is a refusal and the booking is still good — the guest still has a room, so
     * saying otherwise would refund an agency for a stay that is going ahead. Silence
     * is the dangerous one: the room may already be released, so the booking moves to
     * `cancelling` and is settled by reading it back, exactly as an unanswered Book is.
     */
    private function handleCancelFailure(Booking $booking, TboHotelException $e): Booking
    {
        if ($e->status() === null) {
            Log::warning('TBO Hotel cancel unresolved; reconciling', [
                'booking' => $booking->reference,
                'reason' => $e->getMessage(),
            ]);

            $booking = $this->transitionTo($booking, BookingStatus::Cancelling);

            ReconcileHotelCancellation::dispatch($booking->getKey())
                ->delay(now()->addSeconds((int) config('tbohotel.reconcile_delay', 120)));

            return $booking->fresh();
        }

        report($e);

        throw new BookingException($e->getMessage());
    }

    /**
     * @throws BookingException
     */
    private function guardCancellable(Booking $booking): void
    {
        $this->guardReadable($booking);

        if (blank($booking->hotel?->confirmation_number)) {
            throw new BookingException("Booking {$booking->reference} has no confirmation number to cancel.");
        }

        if ($booking->status !== BookingStatus::Confirmed) {
            throw new BookingException("Booking {$booking->reference} is {$booking->status->value} and cannot be cancelled.");
        }
    }

    /**
     * Ask TBO what this booking is now, and write down the answer.
     *
     * The one call that can correct us. Everything else we know about a confirmed stay
     * was true at the moment it was booked: the hotel's own reference arrives days
     * later, an invoice later still, and a cancellation made in TBO's own portal never
     * reaches us at all. Without this the booking page is a photograph.
     *
     * What it will not do is overwrite the terms. §18 makes PreBook's cancellation
     * policy and norms final for the itinerary, so what BookingDetail says about them
     * is a later opinion about a contract already signed.
     *
     * @throws BookingException when the booking is not one we can ask about
     * @throws TboHotelException when TBO cannot be reached or refuses
     */
    public function refresh(Booking $booking): Booking
    {
        $this->guardReadable($booking);

        $stay = $booking->hotel;
        $byConfirmation = filled($stay?->confirmation_number);

        $detail = BookingDetailResult::fromResponse($this->tbo->bookingDetail(
            $byConfirmation ? (string) $stay->confirmation_number : $booking->reference,
            isConfirmationNumber: $byConfirmation,
        ));

        return DB::transaction(function () use ($booking, $stay, $detail): Booking {
            $stay?->forceFill(array_filter([
                'supplier_status' => $detail->status ?: null,
                'room_statuses' => $detail->rooms ?: null,
                // Only ever filled in, never blanked: TBO omits the hotel's reference
                // until it issues one, and an omission must not erase what we hold.
                'hotel_confirmation_number' => $detail->hotelConfirmationNumber,
                'invoice_number' => $detail->invoiceNumber,
                'confirmation_number' => $detail->confirmationNumber,
            ], fn ($value): bool => filled($value)) + ['refreshed_at' => now()])->save();

            return $this->applySupplierState($booking->fresh(), $detail);
        });
    }

    /**
     * Move our status to match TBO's, when TBO has committed to one.
     *
     * Only ever in the direction the state machine already allows, and only on an
     * answer that is an ending. A booking TBO reports as cancelled is refunded, because
     * whoever cancelled it — an agent in their portal, the property, TBO itself — the
     * agency is no longer paying for a room nobody holds.
     */
    private function applySupplierState(Booking $booking, BookingDetailResult $detail): Booking
    {
        $target = match (true) {
            $detail->isConfirmed() => BookingStatus::Confirmed,
            $detail->isCancelled() => BookingStatus::Cancelled,
            $detail->isFailed() => BookingStatus::Failed,
            $detail->isCancelling() => BookingStatus::Cancelling,
            default => null,
        };

        if ($target === null || $target === $booking->status) {
            return $booking;
        }

        if (! $booking->status->canTransitionTo($target)) {
            // Not an error: TBO reporting "Confirmed" for a booking we already
            // cancelled is a stale read, not an instruction to un-cancel it.
            Log::info('TBO Hotel reports a state we cannot move to', [
                'booking' => $booking->reference,
                'ours' => $booking->status->value,
                'theirs' => $detail->status,
            ]);

            return $booking;
        }

        Log::info('Hotel booking status corrected from TBO', [
            'booking' => $booking->reference,
            'from' => $booking->status->value,
            'to' => $target->value,
            'reported' => $detail->status,
        ]);

        return $this->transitionTo($booking, $target);
    }

    /**
     * Record the booking as failed, giving the agency its money back.
     *
     * Only ever called when the supplier has said no. An unanswered Book is not a
     * failure — see handleBookFailure.
     */
    public function fail(Booking $booking, string $reason): Booking
    {
        Log::warning('Hotel booking failed', ['booking' => $booking->reference, 'reason' => $reason]);

        return $this->transitionTo($booking, BookingStatus::Failed);
    }

    /**
     * Decide whether a failed Book is a refusal or a question.
     *
     * The difference is whether TBO answered. A status code in the body is an answer —
     * the booking was not made, the wallet goes back, and the agent is told why. A
     * timeout or a broken connection is not an answer: §10 is explicit that the booking
     * may well exist, so the money stays put and the reference gets read back later.
     *
     * Guessing wrong in one direction refunds an agency for a room its guest will turn
     * up to. In the other it charges them for nothing. Only the supplier can say, so
     * only the supplier is asked.
     */
    private function handleBookFailure(Booking $booking, TboHotelException $e): Booking
    {
        if ($e->status() === null) {
            return $this->reconcileLater($booking, $e->getMessage());
        }

        report($e);

        $this->transitionTo($booking, BookingStatus::Failed);

        throw new BookingException($e->getMessage());
    }

    /**
     * Leave the booking in flight and go and find out what happened.
     *
     * §10, verbatim: *"In case of timeout/failure/http/network related error in book
     * response then it is mandatory to call the BookingDetail method by using
     * BookingReferenceId after 120 seconds of book response."*
     *
     * The booking is never re-Booked. The reference is spent, and asking again could
     * buy the room twice.
     */
    private function reconcileLater(Booking $booking, string $reason): Booking
    {
        Log::warning('TBO Hotel book unresolved; reconciling', [
            'booking' => $booking->reference,
            'reason' => $reason,
        ]);

        ReconcileHotelBooking::dispatch($booking->getKey())
            ->delay(now()->addSeconds((int) config('tbohotel.reconcile_delay', 120)));

        return $booking->fresh();
    }

    /**
     * @throws BookingException
     */
    /**
     * A booking we can ask TBO about at all.
     *
     * Looser than guardBookable: reading is safe in any state, and reading a cancelled
     * or failed booking is often the point. What it cannot cross is the environment —
     * asking a live account about a test reference answers the wrong question, and the
     * answer would then be written down as fact.
     *
     * @throws BookingException
     */
    private function guardReadable(Booking $booking): void
    {
        if ($booking->product !== BookingProduct::Hotel) {
            throw new BookingException("Booking {$booking->reference} is not a hotel booking.");
        }

        if ($booking->environment !== $this->tbo->environment()) {
            throw new BookingException(
                "Booking {$booking->reference} was made on {$booking->environment} and cannot be read on {$this->tbo->environment()}."
            );
        }

        if ($booking->status === BookingStatus::Quoted) {
            throw new BookingException("Booking {$booking->reference} has not been sent to the hotel yet.");
        }
    }

    private function guardBookable(Booking $booking): void
    {
        if ($booking->product !== BookingProduct::Hotel) {
            throw new BookingException("Booking {$booking->reference} is not a hotel booking.");
        }

        // Stamped at creation and immutable. A booking quoted on test must never be
        // sent to production, whatever the platform has been switched to since.
        if ($booking->environment !== $this->tbo->environment()) {
            throw new BookingException(
                "Booking {$booking->reference} was quoted on {$booking->environment} and cannot be booked on {$this->tbo->environment()}."
            );
        }

        if (! in_array($booking->status, [BookingStatus::Quoted, BookingStatus::Processing], true)) {
            throw new BookingException("Booking {$booking->reference} is {$booking->status->value} and cannot be booked.");
        }

        // The rule the whole phase is built on, enforced where every caller passes
        // rather than in one job's guard. A Book that went out and was not answered may
        // well have taken the room; it is settled by reading the reference back, never
        // by asking again.
        if ($booking->hotel?->book_sent_at !== null) {
            throw new BookingException(
                "Booking {$booking->reference} has already been sent to the hotel and is awaiting an answer."
            );
        }
    }
}
