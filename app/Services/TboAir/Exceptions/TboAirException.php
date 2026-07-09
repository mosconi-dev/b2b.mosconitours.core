<?php

namespace App\Services\TboAir\Exceptions;

use RuntimeException;
use Throwable;

class TboAirException extends RuntimeException
{
    /**
     * @param  int|null  $status  the upstream HTTP status, when the failure was an HTTP response
     * @param  bool  $timeout  true for a client-side connection/read timeout
     */
    public function __construct(
        string $message,
        private bool $authError = false,
        ?Throwable $previous = null,
        private readonly ?int $status = null,
        private readonly bool $timeout = false,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function auth(string $message): self
    {
        return new self($message, authError: true);
    }

    public function isAuthError(): bool
    {
        return $this->authError;
    }

    public function status(): ?int
    {
        return $this->status;
    }

    /**
     * The provider didn't respond in time — a client-side connection/read timeout,
     * or an upstream gateway-timeout status (408/502/503/504/522/524).
     */
    public function isTimeout(): bool
    {
        return $this->timeout || in_array($this->status, [408, 502, 503, 504, 522, 524], true);
    }
}
