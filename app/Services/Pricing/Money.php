<?php

namespace App\Services\Pricing;

use InvalidArgumentException;
use Stringable;

/**
 * A decimal amount, in bcmath, at two places.
 *
 * Money never touches a float — WalletService's rule, and markup is the same money.
 * A percentage of a fare computed in floats is off by a centavo often enough that the
 * layers stop summing to the total, and a breakdown that does not add up is worse than
 * one that is slightly wrong: it makes every figure on the page suspect.
 *
 * Immutable. Every operation returns a new instance.
 */
final readonly class Money implements Stringable
{
    public const SCALE = 2;

    /** Working precision for intermediate steps, so a percentage is not rounded twice. */
    private const PRECISION = 6;

    private function __construct(public string $amount) {}

    /**
     * Accepts anything the application already holds money in: a float from a supplier
     * DTO, an int, a decimal string from Eloquent, or "1,500.00" from a form.
     *
     * The thousands separator is stripped first — bcmath reads "1,500.00" as 1, silently,
     * which is the exact trap WalletService::normalize() exists to avoid.
     */
    public static function of(string|float|int $amount): self
    {
        $clean = str_replace(',', '', (string) $amount);

        if (! is_numeric($clean)) {
            throw new InvalidArgumentException("Not a numeric amount: {$amount}");
        }

        return new self(number_format((float) $clean, self::SCALE, '.', ''));
    }

    public static function zero(): self
    {
        return new self('0.00');
    }

    public function plus(self $other): self
    {
        return new self(bcadd($this->amount, $other->amount, self::SCALE));
    }

    public function minus(self $other): self
    {
        return new self(bcsub($this->amount, $other->amount, self::SCALE));
    }

    /**
     * Multiply by a plain ratio — a percentage divided by 100, a head count, a night
     * count.
     *
     * Computed at six places and rounded once at the end, so 10% of 3,333.33 does not
     * lose a centavo to an intermediate rounding it never needed.
     */
    public function times(string|float|int $factor): self
    {
        $product = bcmul($this->amount, (string) $factor, self::PRECISION);

        return self::of($product);
    }

    /**
     * A percentage of this amount. `percent` is the human figure — 10 means 10%.
     */
    public function percent(string|float|int $percent): self
    {
        return $this->times(bcdiv((string) $percent, '100', self::PRECISION));
    }

    public function isZero(): bool
    {
        return $this->compare(self::zero()) === 0;
    }

    public function isNegative(): bool
    {
        return $this->compare(self::zero()) < 0;
    }

    public function compare(self $other): int
    {
        return bccomp($this->amount, $other->amount, self::SCALE);
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    /**
     * Held between an optional floor and an optional cap. Either may be null.
     */
    public function clamp(?self $min, ?self $max): self
    {
        $result = $this;

        if ($min !== null && $result->lessThan($min)) {
            $result = $min;
        }

        if ($max !== null && $result->greaterThan($max)) {
            $result = $max;
        }

        return $result;
    }

    /**
     * Round up to the nearest step — 10, 50, 100 — so a price reads as deliberate
     * rather than computed. A step of 0 or 1 leaves the amount alone.
     */
    public function roundUpTo(int $step): self
    {
        if ($step <= 1) {
            return $this;
        }

        return self::of((string) (ceil((float) $this->amount / $step) * $step));
    }

    /** For display: 5,700.00 */
    public function formatted(): string
    {
        return number_format((float) $this->amount, self::SCALE);
    }

    public function toFloat(): float
    {
        return (float) $this->amount;
    }

    public function __toString(): string
    {
        return $this->amount;
    }
}
