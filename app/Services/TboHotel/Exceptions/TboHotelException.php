<?php

namespace App\Services\TboHotel\Exceptions;

use App\Enums\TboHotelStatus;
use RuntimeException;
use Throwable;

/**
 * A TBO Holidays call that did not produce a usable answer.
 *
 * The predicates matter more than the message: callers branch on *why* — an expired
 * BookingCode sends the agent back to search, an exhausted credit limit is an
 * operations problem, and a timeout on Book is the one case where the booking may
 * exist anyway and must be reconciled rather than retried.
 */
class TboHotelException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?TboHotelStatus $status = null,
        private readonly ?int $statusCode = null,
        private readonly ?int $httpStatus = null,
        private readonly bool $timeout = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Built from the body's `Status` block. TBO's own description is preferred —
     * it is more specific than anything we could write — with the enum's guidance
     * as the fallback, and the bare code when TBO sends something undocumented.
     */
    public static function fromStatus(?TboHotelStatus $status, ?int $code, ?string $description): self
    {
        $message = filled($description)
            ? trim((string) $description)
            : ($status?->message() ?? "TBO returned status {$code}.");

        return new self($message, status: $status, statusCode: $code);
    }

    public static function transport(string $message, ?int $httpStatus = null, bool $timeout = false, ?Throwable $previous = null): self
    {
        return new self($message, httpStatus: $httpStatus, timeout: $timeout, previous: $previous);
    }

    public function status(): ?TboHotelStatus
    {
        return $this->status;
    }

    /**
     * TBO's numeric code, including ones the enum does not know about.
     */
    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function isNoAvailability(): bool
    {
        return $this->status === TboHotelStatus::NoAvailability;
    }

    /**
     * The 30-minute search→book window elapsed. Re-search, never retry.
     */
    public function isExpired(): bool
    {
        return $this->status === TboHotelStatus::BookingCodeExpired;
    }

    public function isRateGone(): bool
    {
        return $this->status === TboHotelStatus::RateUnavailable;
    }

    /**
     * Our credit limit with TBO — not the agency e-wallet. The two fail for
     * different people and need different words.
     */
    public function isInsufficientFunds(): bool
    {
        return $this->status === TboHotelStatus::InsufficientBalance;
    }

    public function isThrottled(): bool
    {
        return $this->status === TboHotelStatus::Throttled || $this->httpStatus === 429;
    }

    public function isUnauthorized(): bool
    {
        return $this->status === TboHotelStatus::Unauthorized || $this->httpStatus === 401;
    }

    /**
     * No answer arrived in time — a client-side timeout, or an upstream gateway
     * giving up. On a write this is the ambiguous case: the request may have landed.
     */
    public function isTimeout(): bool
    {
        return $this->timeout || in_array($this->httpStatus, [408, 502, 503, 504, 522, 524], true);
    }
}
