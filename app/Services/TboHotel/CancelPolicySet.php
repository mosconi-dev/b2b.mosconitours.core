<?php

namespace App\Services\TboHotel;

use Carbon\CarbonInterface;
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
     * Rebuild from the bucketed shape we stored at PreBook time.
     *
     * The terms §18 makes final live in `hotel_bookings.cancel_policies`, already
     * bucketed and already date-corrected. Reading them back through fromResponse()
     * would try to parse DD-MM-YYYY dates that are ISO by then.
     *
     * @param  array<string, mixed>|null  $buckets
     */
    public static function fromStored(?array $buckets): self
    {
        $clean = [];

        foreach ((array) $buckets as $key => $policies) {
            foreach ((array) $policies as $policy) {
                if (! is_array($policy)) {
                    continue;
                }

                $clean[(string) $key][] = [
                    'from' => filled($policy['from'] ?? null) ? (string) $policy['from'] : null,
                    'chargeType' => (string) ($policy['chargeType'] ?? ''),
                    'charge' => (float) ($policy['charge'] ?? 0),
                ];
            }
        }

        return new self($clean);
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

    /**
     * The whole schedule as one flat list, oldest first, for showing an agent what
     * cancelling costs and from when.
     *
     * `freeUntil()` answers only half the question — it says when the free window shuts
     * and nothing about what the rate costs afterwards, which is the half that loses
     * money. This is the other half.
     *
     * A row carries its room number only when the rooms genuinely differ; TBO omits
     * Index unless they do, and labelling every row "Room 1" on a one-room stay is
     * noise. Entries with no date are dropped: a charge that starts at no stated time
     * cannot be acted on.
     *
     * @return array<int, array{room: int|null, from: string, chargeType: string, charge: float}>
     */
    public function schedule(): array
    {
        $perRoom = count($this->buckets) > 1;
        $rows = [];

        foreach ($this->buckets as $key => $policies) {
            foreach ($policies as $policy) {
                if ($policy['from'] === null) {
                    continue;
                }

                $rows[] = [
                    'room' => $perRoom && $key !== self::ALL_ROOMS ? (int) $key : null,
                    'from' => $policy['from'],
                    'chargeType' => $policy['chargeType'],
                    'charge' => $policy['charge'],
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['room'], $a['from']] <=> [$b['room'], $b['from']]);

        return $rows;
    }

    /**
     * What cancelling costs at a given moment.
     *
     * The ladder is read the way it is written: the applicable rung is the last one
     * whose date has already passed. Before the first rung there is nothing to pay,
     * which is what a free-cancellation window is.
     *
     * **An estimate, and labelled as one wherever it is shown.** TBO's Cancel response
     * does not state the charge and its invoice is the only settlement, so this is our
     * reading of the terms rather than a figure they have agreed to. Two places it can
     * legitimately differ: a percentage on a per-room policy is applied to that room's
     * even share of a total TBO only ever gave us combined, and a rate whose policy we
     * never received is treated as free rather than guessed at.
     *
     * Never more than was paid — a cancellation cannot cost more than the stay.
     */
    public function chargeAt(CarbonInterface $at, float $totalFare, int $rooms = 1): float
    {
        // Per-room policies win when TBO sent them; the 'all' bucket is the fallback it
        // sends instead, not as well. Adding both would charge the same stay twice.
        $numbered = array_diff_key($this->buckets, [self::ALL_ROOMS => null]);
        $buckets = $numbered !== [] ? $numbered : array_intersect_key($this->buckets, [self::ALL_ROOMS => null]);

        if ($buckets === []) {
            return 0.0;
        }

        $share = $numbered !== [] ? $totalFare / max(1, $rooms) : $totalFare;
        $charge = 0.0;

        foreach ($buckets as $policies) {
            $charge += $this->rungAt($policies, $at, $share);
        }

        return round(min($charge, $totalFare), 2);
    }

    /**
     * The applicable rung of one bucket's ladder, as an amount.
     *
     * @param  array<int, array{from: string|null, chargeType: string, charge: float}>  $policies
     */
    private function rungAt(array $policies, CarbonInterface $at, float $share): float
    {
        $due = 0.0;

        foreach ($policies as $policy) {
            if ($policy['from'] === null || Carbon::parse($policy['from'])->greaterThan($at)) {
                continue;
            }

            // Sorted oldest first, so the last one that has started is the live one.
            $due = strcasecmp($policy['chargeType'], 'Percentage') === 0
                ? $share * ($policy['charge'] / 100)
                : $policy['charge'];
        }

        return max(0.0, $due);
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
