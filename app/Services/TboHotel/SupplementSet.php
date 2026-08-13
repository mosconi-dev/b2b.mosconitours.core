<?php

namespace App\Services\TboHotel;

/**
 * Charges attached to a rate, split by who collects them.
 *
 * `AtProperty` is the reason this exists: those are paid by the guest at the desk,
 * are not in the price we take, and §18 requires them to be shown before booking.
 * A guest surprised by a 500-peso deposit at check-in is a complaint we caused.
 */
readonly class SupplementSet
{
    public const ALL_ROOMS = 'all';

    /**
     * @param  array<string, array<int, array{type: string, description: string, price: float, currency: string}>>  $buckets
     */
    private function __construct(public array $buckets) {}

    public static function fromResponse(mixed $raw): self
    {
        $buckets = [];

        foreach (self::flatten($raw) as $supplement) {
            $supplement = (array) $supplement;
            $index = $supplement['Index'] ?? null;
            $key = filled($index) ? (string) $index : self::ALL_ROOMS;

            $buckets[$key][] = [
                'type' => (string) ($supplement['Type'] ?? ''),
                // TBO sends machine strings into a guest-facing field.
                'description' => self::label((string) ($supplement['Description'] ?? '')),
                'price' => (float) ($supplement['Price'] ?? 0),
                'currency' => (string) ($supplement['Currency'] ?? ''),
            ];
        }

        return new self($buckets);
    }

    /**
     * Rebuild from what was stored on a booking.
     *
     * hotel_bookings keeps the normalised buckets, not TBO's envelope, so reading them
     * back needs a way in that skips the parsing. Without it the model was filtering
     * the outer array — whose members are lists of supplements, not supplements — and
     * quietly returning nothing.
     *
     * @param  array<string, mixed>  $buckets
     */
    public static function fromStored(?array $buckets): self
    {
        return new self($buckets ?? []);
    }

    /**
     * Everything the guest pays at the hotel, across all rooms.
     *
     * @return array<int, array{type: string, description: string, price: float, currency: string}>
     */
    public function payableAtProperty(): array
    {
        $rows = [];

        // Tolerant of shape, because this also reads rows written by older code and a
        // malformed supplement must not take down a booking page or a voucher.
        foreach ($this->buckets as $supplements) {
            foreach ((array) $supplements as $supplement) {
                if (! is_array($supplement)) {
                    continue;
                }

                if (strcasecmp((string) ($supplement['type'] ?? ''), 'AtProperty') === 0) {
                    $rows[] = $supplement + ['description' => '', 'price' => 0.0, 'currency' => ''];
                }
            }
        }

        return $rows;
    }

    /**
     * The at-property charges, one line per distinct charge, counted.
     *
     * TBO states these per room, so a three-room booking with one deposit repeats that
     * deposit three times. Listed as three identical lines it reads as either one
     * charge printed thrice or three unrelated fees, and the guest is about to be asked
     * for the sum of them at the desk. So: one line, "× 3", and what it comes to.
     *
     * @return array<int, array{description: string, price: float, currency: string, count: int, total: float}>
     */
    public function payableAtPropertyGrouped(): array
    {
        $grouped = [];

        foreach ($this->payableAtProperty() as $supplement) {
            $key = $supplement['description'].'|'.$supplement['price'].'|'.$supplement['currency'];

            $grouped[$key] ??= [
                'description' => $supplement['description'],
                'price' => $supplement['price'],
                'currency' => $supplement['currency'],
                'count' => 0,
                'total' => 0.0,
            ];

            $grouped[$key]['count']++;
            $grouped[$key]['total'] += $supplement['price'];
        }

        return array_values($grouped);
    }

    public function payableAtPropertyTotal(): float
    {
        return array_sum(array_column($this->payableAtProperty(), 'price'));
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
     * The sample response nests these one level deeper than the parameter table
     * says (`[[{…}]]`), and the live payload agrees with the sample.
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
            if (is_array($entry) && ! array_key_exists('Type', $entry) && ! array_key_exists('Price', $entry)) {
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
     * TBO mixes prose ("Deposit Fee per night") with machine tokens
     * ("mandatory_tax", "mandatory_fee", "resort_fee") in the same field, and these
     * end up in front of an agent explaining what a guest owes at the desk.
     *
     * The tokens are rewritten by shape rather than by name: a list of known ones
     * needs extending every time TBO invents a fee, and the one it misses is shown
     * raw. Prose is left exactly as written.
     */
    private static function label(string $description): string
    {
        $description = trim($description);

        if ($description === '') {
            return 'Additional charge';
        }

        return preg_match('/^[a-z0-9]+(_[a-z0-9]+)+$/', $description) === 1
            ? ucfirst(str_replace('_', ' ', $description))
            : $description;
    }
}
