<?php

namespace App\Services\Booking\Exceptions;

use App\Models\Booking;
use RuntimeException;

/**
 * A booking domain rule was violated (illegal state transition, missing passport
 * when the fare requires it, etc.). Controllers surface the message to the user.
 */
class BookingException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $unresolved = false)
    {
        parent::__construct($message);
    }

    /**
     * The supplier's answer could not be established, even after reading
     * GetBookingDetails.
     *
     * Deliberately **not** a failure. A booking here may well have a live PNR at TBO,
     * so it keeps its current status: marking it failed would refund the agency for a
     * seat that is still held, and retrying it could sell that seat twice. It needs a
     * person, and the message says so.
     */
    public static function unresolved(Booking $booking, ?string $pnr): self
    {
        $reference = $pnr === null
            ? "Booking {$booking->reference}"
            : "Booking {$booking->reference} (airline reference {$pnr})";

        return new self(
            "{$reference} could not be confirmed with the airline and needs to be checked manually before it is retried.",
            unresolved: true,
        );
    }
}
