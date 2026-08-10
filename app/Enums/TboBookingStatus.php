<?php

namespace App\Enums;

/**
 * TBO's own booking status, as returned by Book / Ticket / GetBookingDetails.
 *
 * Ten values against our six `BookingStatus` cases, and the mismatch is the point:
 * three of them mean "we do not know yet" and must be resolved by reading
 * GetBookingDetails rather than by trusting the write call. Certification **Case 11
 * tests `InProgress` handling explicitly**, so this is required behaviour.
 */
enum TboBookingStatus: int
{
    case NotSet = 0;
    case Successful = 1;
    case Failed = 2;
    case OtherFare = 3;
    case OtherClass = 4;
    case BookedOther = 5;
    case NotConfirmed = 6;
    case Pending = 7;
    case InProgress = 8;
    case Cancelled = 9;

    public static function tryFromResponse(mixed $status): ?self
    {
        return is_numeric($status) ? self::tryFrom((int) $status) : null;
    }

    /**
     * Whether the outcome is genuinely unresolved.
     *
     * These are **not** failures. A PNR may well exist — treating one as a failure
     * risks abandoning a booking TBO went on to confirm, and retrying it risks a
     * double sell. The only correct response is to read GetBookingDetails and act on
     * what it says.
     *
     * `NotSet` is included: a status TBO did not fill in tells us nothing, and
     * guessing "failed" from silence is the same mistake.
     */
    public function isAmbiguous(): bool
    {
        return match ($this) {
            self::NotSet, self::NotConfirmed, self::Pending, self::InProgress => true,
            default => false,
        };
    }

    public function isSuccess(): bool
    {
        return $this === self::Successful;
    }

    /**
     * Whether TBO has definitively rejected the attempt.
     *
     * `OtherFare` and `OtherClass` mean the seat was gone at the fare or class we
     * asked for — a refusal to sell what we requested, not an error to retry blindly.
     * `BookedOther` is deliberately **not** here: it means something *was* booked, so
     * it resolves through GetBookingDetails like any other live PNR.
     */
    public function isFailure(): bool
    {
        return match ($this) {
            self::Failed, self::OtherFare, self::OtherClass, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * The booking status this maps to **after a ticketing call**.
     *
     * The same code means different things depending on which method returned it:
     * `Successful` from Ticket means issued, but `Successful` from Book only means a
     * PNR was held. So this mapping is written from the Ticket/GetBookingDetails
     * perspective, and `BookingService::book()` maps its own outcome rather than
     * calling this.
     *
     * Returns **null** for every ambiguous case — the caller must reconcile via
     * GetBookingDetails instead. A null here is a deliberate refusal to guess, not a
     * missing branch.
     */
    public function toBookingStatus(): ?BookingStatus
    {
        return match ($this) {
            self::Successful => BookingStatus::Ticketed,
            self::Failed, self::OtherFare, self::OtherClass => BookingStatus::Failed,
            self::Cancelled => BookingStatus::Cancelled,
            self::BookedOther => BookingStatus::Booked,
            self::NotSet, self::NotConfirmed, self::Pending, self::InProgress => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NotSet => 'Not set',
            self::BookedOther => 'Booked (other)',
            self::NotConfirmed => 'Not confirmed',
            self::InProgress => 'In progress',
            self::OtherFare => 'Other fare',
            self::OtherClass => 'Other class',
            default => ucfirst(strtolower($this->name)),
        };
    }
}
