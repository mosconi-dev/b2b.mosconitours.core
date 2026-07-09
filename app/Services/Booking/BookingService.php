<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\DTO\Passenger;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\TboAir\DTO\SelectionInput;
use App\Services\TboAir\TboAirService;
use Illuminate\Support\Str;

/**
 * Owns the booking lifecycle: creates a durable, retry-safe record from a fresh
 * FareQuote and guards every state transition. No TBO write (Book/Ticket) happens
 * here — that lands in a later phase and hangs off this record.
 */
class BookingService
{
    public function __construct(private readonly TboAirService $tbo) {}

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

        return Booking::create([
            'reference' => $this->reference(),
            'user_id' => $user->getKey(),
            'environment' => $this->tbo->environment(),
            'status' => BookingStatus::Quoted,
            'trace_id' => $selection->traceId,
            'result_index' => $selection->resultIndex,
            'is_lcc' => $quote->isLcc,
            'currency' => $quote->price['currency'],
            'total_amount' => $quote->price['offeredFare'],
            'quote' => $quote->toArray(),
            'pax' => array_map(fn (Passenger $p): array => $p->toArray(), $passengers),
            'contact' => $contact,
        ]);
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

        $booking->fill($attributes);
        $booking->status = $to;
        $booking->save();

        return $booking;
    }

    private function reference(): string
    {
        return 'MT-'.strtoupper(Str::random(8));
    }
}
