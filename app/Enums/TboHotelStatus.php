<?php

namespace App\Enums;

/**
 * TBO Holidays' response status (§5 of the v2.1 specification).
 *
 * It arrives in the body as `Status.Code`, not as the HTTP status — a refusal is
 * routinely delivered inside an HTTP 200 — so this, and not the transport, is what
 * decides whether a call worked.
 */
enum TboHotelStatus: int
{
    case Success = 200;
    case NoAvailability = 201;
    case RateUnavailable = 207;
    case InsufficientBalance = 300;
    case BookingCodeExpired = 315;
    case InvalidRequest = 400;
    case Unauthorized = 401;
    case AgentBlocked = 402;
    case BookingFailed = 405;
    case Throttled = 429;
    case CancelFailed = 479;
    case UnexpectedError = 500;

    /**
     * Whether the call did what was asked.
     *
     * `NoAvailability` is excluded on purpose: it is a true and useful answer to a
     * search, but it is not a successful PreBook or Book.
     */
    public function isSuccess(): bool
    {
        return $this === self::Success;
    }

    /**
     * Whether the response carries a usable payload. A search that found nothing
     * still answered the question.
     */
    public function isUsable(): bool
    {
        return $this === self::Success || $this === self::NoAvailability;
    }

    /**
     * Whether trying the same request again could plausibly succeed.
     *
     * Only throttling qualifies. An expired BookingCode or a vanished rate needs a
     * new search, not a retry, and repeating either just burns quota.
     */
    public function isRetryable(): bool
    {
        return $this === self::Throttled;
    }

    /**
     * What to tell the agent. TBO's own `Description` is preferred where it exists;
     * these are the fallbacks, and they say what to do rather than what went wrong.
     */
    public function message(): string
    {
        return match ($this) {
            self::Success => 'Successful.',
            self::NoAvailability => 'No rooms are available for these dates and occupancy.',
            self::RateUnavailable => 'That rate has just been taken. Search again for current availability.',
            self::InsufficientBalance => 'Our TBO credit limit is exhausted — this is the supplier balance, not the agency wallet.',
            self::BookingCodeExpired => 'These prices have expired. Search again to continue.',
            self::InvalidRequest => 'TBO rejected the request as invalid.',
            self::Unauthorized => 'TBO rejected our credentials.',
            self::AgentBlocked => 'Our agency is blocked at TBO.',
            self::BookingFailed => 'TBO could not create the booking.',
            self::Throttled => 'Too many requests to TBO at once. Try again in a moment.',
            self::CancelFailed => 'TBO could not cancel this booking.',
            self::UnexpectedError => 'TBO reported an unexpected error.',
        };
    }
}
