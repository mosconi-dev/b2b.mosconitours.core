<?php

namespace App\Enums;

/**
 * Lifecycle of a booking. Transitions are guarded so a retry or an out-of-order
 * call can never move a booking into an illegal state.
 *
 * Two branches share the enum, because they share the money and the state machine
 * but not the vocabulary. **Flights** end at `Ticketed`, passing through `Booked`
 * while a PNR is held. **Hotels** end at `Confirmed` — one call vouchers them, so
 * there is no hold and nothing to issue — and pass through `Cancelling`, because a
 * hotel cancellation is a request the supplier may take time to honour.
 *
 * Neither branch enters the other's states; the guards below make that structural
 * rather than a convention.
 */
enum BookingStatus: string
{
    case Quoted = 'quoted';         // priced + travellers captured; nothing sent to the supplier yet
    case Processing = 'processing'; // in flight with the supplier, or awaiting reconciliation
    case Booked = 'booked';         // flight only: PNR held, mid-chain — not a resting state
    case Ticketed = 'ticketed';     // flight only: issued
    case Confirmed = 'confirmed';   // hotel only: vouchered
    case Cancelling = 'cancelling'; // hotel only: cancellation requested, not yet honoured
    case Failed = 'failed';         // an attempt failed
    case Cancelled = 'cancelled';   // released / voided / cancelled
    case Refunded = 'refunded';

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Quoted => [self::Processing, self::Booked, self::Ticketed, self::Confirmed, self::Failed, self::Cancelled],
            self::Processing => [self::Booked, self::Ticketed, self::Confirmed, self::Failed, self::Cancelled],
            self::Booked => [self::Ticketed, self::Failed, self::Cancelled],
            self::Ticketed => [self::Cancelled, self::Refunded],
            self::Confirmed => [self::Cancelling, self::Cancelled, self::Refunded],
            // A refused cancellation is not a failure — the booking is still good, and
            // saying otherwise would refund an agency whose guest still has a room.
            self::Cancelling => [self::Cancelled, self::Confirmed],
            self::Cancelled => [self::Refunded],
            self::Failed, self::Refunded => [],
        };
    }

    /**
     * Whether the supplier still owes us an answer.
     *
     * `Booked` counts: a non-LCC booking passes through it on the way to a ticket, and
     * a booking sitting there is a held PNR nobody has paid for — something to finish
     * or release, never somewhere to stop. `Cancelling` counts for the same reason in
     * reverse: a cancellation TBO has not yet honoured is not a cancelled booking.
     */
    public function isInFlight(): bool
    {
        return $this === self::Processing || $this === self::Booked || $this === self::Cancelling;
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Tailwind pill classes for rendering the status as a badge.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Ticketed, self::Confirmed => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::Processing, self::Cancelling => 'bg-sky-50 text-sky-700 ring-sky-600/20',
            self::Booked => 'bg-blue-50 text-blue-700 ring-blue-600/20',
            self::Quoted => 'bg-gray-100 text-gray-600 ring-gray-500/20',
            self::Cancelled => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            self::Refunded => 'bg-violet-50 text-violet-700 ring-violet-600/20',
            self::Failed => 'bg-red-50 text-red-700 ring-red-600/20',
        };
    }
}
