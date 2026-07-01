<?php

namespace App\Services\TboAir\Exceptions;

use RuntimeException;
use Throwable;

class TboAirException extends RuntimeException
{
    public function __construct(string $message, private bool $authError = false, ?Throwable $previous = null)
    {
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
}
