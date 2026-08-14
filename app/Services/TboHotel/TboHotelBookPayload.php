<?php

namespace App\Services\TboHotel;

use App\Models\Booking;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\TboHotel\DTO\Guest;

/**
 * The Book request, assembled from what we already stored.
 *
 * Pure: it reads the booking and returns an array, touching nothing. That matters
 * because Book is the one call that spends money — being able to build and inspect the
 * exact payload without sending it is the difference between a test and a reservation.
 *
 * Nothing here is re-derived from the supplier. The BookingCode, the price and the
 * guests were settled at PreBook and written down; if any of it were fetched again now
 * the booking could be made on terms nobody agreed to.
 */
class TboHotelBookPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Booking $booking): array
    {
        $detail = $booking->hotel;

        if ($detail === null) {
            throw new BookingException("Booking {$booking->reference} has no hotel detail to book.");
        }

        if (blank($detail->booking_code)) {
            throw new BookingException("Booking {$booking->reference} has no BookingCode.");
        }

        $contact = (array) ($booking->contact ?? []);

        return [
            'BookingCode' => $detail->booking_code,
            'CustomerDetails' => self::customers($booking),

            // Both ours, and both the same. ClientReferenceId is what appears on TBO's
            // invoice; BookingReferenceId is the key BookingDetail accepts when no
            // ConfirmationNumber came back — which is precisely the timeout case this
            // whole phase exists for. It has to be a value we chose and stored before
            // the call, never anything the supplier issues.
            'ClientReferenceId' => $booking->reference,
            'BookingReferenceId' => $booking->reference,

            // PreBook's figure, which is what the agency was charged. Search's price
            // has no standing by now and TBO refuses a mismatch.
            'TotalFare' => (float) $booking->total_amount,

            'EmailId' => (string) ($contact['email'] ?? ''),
            'PhoneNumber' => (string) ($contact['phone'] ?? ''),

            // Vouchered outright: there is no hold in this API, so a booking is either
            // made or not. Limit books against TBO's credit line — cards are out of
            // scope, we settle on invoice.
            'BookingType' => 'Voucher',
            'PaymentMode' => 'Limit',
        ];
    }

    /**
     * Guests grouped into the rooms they sleep in.
     *
     * TBO takes one CustomerDetails entry per room, and puts the occupants of that room
     * inside it. A guest in the wrong group is a guest in the wrong bed, and the hotel
     * allocates from this.
     *
     * @return array<int, array{CustomerNames: array<int, array<string, string>>}>
     */
    private static function customers(Booking $booking): array
    {
        $rooms = [];

        foreach ((array) ($booking->pax ?? []) as $stored) {
            $guest = Guest::fromArray((array) $stored);
            $rooms[$guest->roomIndex][] = $guest->toPayload();
        }

        if ($rooms === []) {
            throw new BookingException("Booking {$booking->reference} has no guests.");
        }

        // Keyed by room index while grouping, so the order is the order they were
        // priced in rather than the order they happen to come out of the column.
        ksort($rooms);

        return array_map(
            fn (array $names): array => ['CustomerNames' => $names],
            array_values($rooms),
        );
    }
}
