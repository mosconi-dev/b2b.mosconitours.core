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
     * Everything the guest pays at the hotel, across all rooms.
     *
     * @return array<int, array{type: string, description: string, price: float, currency: string}>
     */
    public function payableAtProperty(): array
    {
        $rows = [];

        foreach ($this->buckets as $supplements) {
            foreach ($supplements as $supplement) {
                if (strcasecmp($supplement['type'], 'AtProperty') === 0) {
                    $rows[] = $supplement;
                }
            }
        }

        return $rows;
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

    private static function label(string $description): string
    {
        $description = trim($description);

        return match (strtolower($description)) {
            'mandatory_tax' => 'Mandatory tax',
            '' => 'Additional charge',
            default => $description,
        };
    }
}
