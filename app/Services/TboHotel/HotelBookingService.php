<?php

namespace App\Services\TboHotel;

use App\Enums\BookingProduct;
use App\Enums\BookingStatus;
use App\Enums\Supplier;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\User;
use App\Services\Booking\Concerns\ChargesWallet;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\TboHotel\DTO\Guest;
use App\Services\TboHotel\DTO\PreBookResult;
use App\Services\TboHotel\DTO\SearchInput;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;

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
    use ChargesWallet;

    public function __construct(
        private readonly TboHotelService $tbo,
        private readonly WalletService $wallets,
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
     * @throws Exceptions\TboHotelException when the rate has expired or vanished
     */
    public function createFromQuote(
        User $user,
        SearchInput $search,
        string $bookingCode,
        array $guests,
        array $contact,
        ?float $shownFare = null,
        bool $acceptPriceChange = false,
    ): Booking {
        $quote = $this->tbo->preBook($bookingCode);

        // A price move between Search and PreBook is normal. It is only an error if
        // nobody agreed to it — booking silently at the new price spends an agency's
        // money on a figure it never saw.
        if ($shownFare !== null && $quote->priceChanged($shownFare) && ! $acceptPriceChange) {
            throw new BookingException(sprintf(
                'The hotel re-priced this room from %s to %s. Confirm the new price to continue.',
                number_format($shownFare, 2),
                number_format($quote->totalFare(), 2),
            ));
        }

        $guests = $this->guardGuests($guests, $search);

        $hotel = Hotel::where('code', $quote->hotelCode ?: $search->locationCode)->first();
        $total = number_format($quote->totalFare(), 2, '.', '');

        // PreBook above is a supplier read and stays outside the transaction. Only the
        // rows and the wallet charge go inside, so a short balance rolls the whole
        // booking back rather than leaving one nobody paid for.
        return DB::transaction(function () use ($user, $search, $quote, $guests, $contact, $total, $hotel): Booking {
            $booking = Booking::create([
                'reference' => $this->reference(),
                'product' => BookingProduct::Hotel,
                'supplier' => Supplier::TboHotel,
                'user_id' => $user->getKey(),
                'agency_id' => $user->agency_id,
                'environment' => $this->tbo->environment(),
                'status' => BookingStatus::Quoted,
                'currency' => $quote->currency,
                'total_amount' => $total,
                'quote' => $quote->toArray(),
                // The browser-facing snapshot above cannot rebuild a Book payload —
                // keep what TBO actually sent.
                'quote_raw' => $quote->raw,
                'pax' => array_map(fn (Guest $g): array => $g->toArray(), $guests),
                'contact' => $contact,
            ]);

            $booking->hotel()->create($this->detail($quote, $search, $guests, $hotel));

            $this->chargeWallet($booking, $user, $total);

            return $booking;
        });
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
}
