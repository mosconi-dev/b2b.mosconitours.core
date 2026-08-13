<?php

namespace App\Services\TboHotel;

use Illuminate\Support\Carbon;

/**
 * A bookable unit's cancellation policy, bucketed by the room it applies to.
 *
 * Two things make this worth its own object. TBO's `Index` is optional — when it is
 * absent the policy covers the whole booking rather than one room — and the dates
 * are `DD-MM-YYYY`, so "11-08-2026" is 11 August. Read as ISO it becomes November
 * and every refund window is three months wrong.
 */
readonly class CancelPolicySet
{
    public const ALL_ROOMS = 'all';

    /**
     * @param  array<string, array<int, array{from: string|null, chargeType: string, charge: float}>>  $buckets
     */
    private function __construct(public array $buckets) {}

    /**
     * @param  mixed  $raw  TBO's CancelPolicies, whatever shape it arrived in
     */
    public static function fromResponse(mixed $raw): self
    {
        $buckets = [];

        foreach (self::flatten($raw) as $policy) {
            $policy = (array) $policy;
            $index = $policy['Index'] ?? null;
            $key = filled($index) ? (string) $index : self::ALL_ROOMS;

            $buckets[$key][] = [
                'from' => self::date($policy['FromDate'] ?? null),
                'chargeType' => (string) ($policy['ChargeType'] ?? ''),
                'charge' => (float) ($policy['CancellationCharge'] ?? 0),
            ];
        }

        foreach ($buckets as $key => $policies) {
            usort($policies, fn (array $a, array $b): int => strcmp((string) $a['from'], (string) $b['from']));
            $buckets[$key] = $policies;
        }

        return new self($buckets);
    }

    /**
     * The policy for one room, falling back to the whole-booking bucket.
     *
     * @return array<int, array{from: string|null, chargeType: string, charge: float}>
     */
    public function forRoom(int $index): array
    {
        return $this->buckets[(string) $index] ?? $this->buckets[self::ALL_ROOMS] ?? [];
    }

    /**
     * The moment free cancellation ends — the first policy that charges anything.
     *
     * An exclusive bound: cancelling *at* this instant is already chargeable. Since
     * TBO lands these on midnight, the page says "free cancellation before 4 Sept"
     * rather than "until", which would read as though the 4th were still free.
     *
     * Null when that moment has already passed, which is the common case: TBO
     * describes a non-refundable rate as a zero charge until today and 100% after,
     * so a naive reading advertises "free cancellation until" a date in the past.
     * A window that has closed is not a benefit and must not be shown as one.
     */
    public function freeUntil(): ?string
    {
        $earliest = null;

        foreach ($this->buckets as $policies) {
            foreach ($policies as $policy) {
                if ($policy['charge'] > 0 && $policy['from'] !== null) {
                    $earliest = $earliest === null ? $policy['from'] : min($earliest, $policy['from']);
                    break;
                }
            }
        }

        return $earliest !== null && Carbon::parse($earliest)->isFuture() ? $earliest : null;
    }

    public function isEmpty(): bool
    {
        return $this->buckets === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->buckets;
    }

    /**
     * §6.2 types this as an array of objects and §7.2 as a "List of String Array".
     * Accept either, and one level of unnecessary nesting besides.
     *
     * @return array<int, mixed>
     */
    private static function flatten(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $flat = [];

        foreach ($raw as $entry) {
            if (is_array($entry) && ! array_key_exists('FromDate', $entry) && ! array_key_exists('ChargeType', $entry)) {
                foreach ($entry as $nested) {
                    $flat[] = $nested;
                }

                continue;
            }

            $flat[] = $entry;
        }

        return $flat;
    }

    /**
     * TBO sends DD-MM-YYYY HH:MM:SS. Anything else is returned untouched rather
     * than guessed at — a misparsed cancellation date is worse than an unparsed one.
     */
    private static function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $parsed = Carbon::createFromFormat('d-m-Y H:i:s', $value)
            ?: Carbon::createFromFormat('d-m-Y', $value);

        return $parsed ? $parsed->toDateTimeString() : $value;
    }
}
