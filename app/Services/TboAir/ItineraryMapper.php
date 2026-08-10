<?php

namespace App\Services\TboAir;

use Illuminate\Support\Arr;

/**
 * Normalizes TBO's `Segments` block into trips of legs.
 *
 * Search results and the binding re-price (FareQuote) return the same segment
 * shape, so both go through here — the booking page renders the same itinerary
 * as the results page without a second provider call.
 */
class ItineraryMapper
{
    /**
     * Normalize the Segments structure (nested-per-direction, or flat with
     * TripIndicator) into trips of legs.
     *
     * @return array<int, array{direction: string, stops: int, duration: int, segments: array<int, array<string, mixed>>}>
     */
    public function trips(mixed $segments): array
    {
        if (! is_array($segments) || $segments === []) {
            return [];
        }

        $groups = [];

        if (self::isNestedList($segments)) {
            // Segments[0] = outbound legs, Segments[1] = inbound legs, ...
            foreach ($segments as $legs) {
                if (is_array($legs)) {
                    $groups[] = array_values(array_filter($legs, 'is_array'));
                }
            }
        } else {
            // Flat list of legs carrying TripIndicator (1=outbound, 2=inbound).
            $byIndicator = [];
            foreach ($segments as $leg) {
                if (is_array($leg)) {
                    $byIndicator[(int) data_get($leg, 'TripIndicator', 1)][] = $leg;
                }
            }
            ksort($byIndicator);
            $groups = array_values($byIndicator);
        }

        $trips = [];

        foreach ($groups as $directionIndex => $legs) {
            $count = count($legs);
            $mapped = [];

            foreach ($legs as $idx => $leg) {
                $segment = $this->mapSegment($leg);
                $segment['layoverAfter'] = $idx < $count - 1
                    ? $this->layover(data_get($leg, 'Destination.ArrTime'), data_get($legs[$idx + 1], 'Origin.DepTime'))
                    : null;
                $mapped[] = $segment;
            }

            $trips[] = [
                'direction' => $directionIndex === 0 ? 'outbound' : 'inbound',
                'stops' => max($count - 1, 0),
                'duration' => (int) array_sum(array_map(fn (array $s): int => (int) $s['duration'], $mapped)),
                'segments' => $mapped,
            ];
        }

        return $trips;
    }

    /**
     * Every leg of every trip, flattened — the itinerary-wide view used for
     * allowances that must hold across the whole journey.
     *
     * @param  array<int, array<string, mixed>>  $trips
     * @return array<int, array<string, mixed>>
     */
    public function legs(array $trips): array
    {
        return array_merge([], ...array_map(
            fn (array $trip): array => $trip['segments'] ?? [],
            $trips
        ));
    }

    /**
     * The smallest baggage allowance across every leg, so a summary never
     * promises more than the whole itinerary guarantees (a 20 KG first leg can be
     * followed by a 0 KG LCC connection).
     *
     * TBO returns free text ("20 KG", "2 Piece"), so we only compare legs that share
     * a unit; anything unparseable or mixed falls back to the first leg's own wording
     * rather than inventing a number.
     *
     * @param  array<int, array<string, mixed>>  $legs
     */
    public function lowestAllowance(array $legs, string $key): ?string
    {
        $values = array_values(array_filter(
            array_map(fn (array $leg): mixed => $leg[$key] ?? null, $legs),
            fn (mixed $value): bool => is_string($value) && trim($value) !== ''
        ));

        if ($values === []) {
            return null;
        }

        $units = [];
        $lowest = null;
        $lowestAmount = null;

        foreach ($values as $value) {
            if (! preg_match('/^\s*(\d+(?:\.\d+)?)\s*(\D*)$/', $value, $matches)) {
                return $values[0];
            }

            $units[strtolower(trim($matches[2]))] = true;
            $amount = (float) $matches[1];

            if ($lowestAmount === null || $amount < $lowestAmount) {
                $lowestAmount = $amount;
                $lowest = $value;
            }
        }

        return count($units) === 1 ? $lowest : $values[0];
    }

    /**
     * A single result/leg is an associative array; a nested results/segments
     * container has plain (list) arrays as its elements.
     *
     * @param  array<int|string, mixed>  $items
     */
    public static function isNestedList(array $items): bool
    {
        $first = Arr::first($items);

        return is_array($first) && array_is_list($first);
    }

    /**
     * @param  array<string, mixed>  $leg
     * @return array<string, mixed>
     */
    private function mapSegment(array $leg): array
    {
        $airlineCode = (string) data_get($leg, 'Airline.AirlineCode', '');
        $flightNumber = (string) data_get($leg, 'Airline.FlightNumber', '');

        return [
            'airlineCode' => $airlineCode,
            'airlineName' => (string) data_get($leg, 'Airline.AirlineName', ''),
            'flightNumber' => trim($airlineCode.$flightNumber),
            'fareClass' => (string) data_get($leg, 'Airline.FareClass', ''),
            'cabin' => $this->cabinLabel((int) data_get($leg, 'CabinClass', 0)),
            'duration' => (int) data_get($leg, 'Duration', 0),
            'baggage' => data_get($leg, 'Baggage'),
            'cabinBaggage' => data_get($leg, 'CabinBaggage'),
            // Search-only: TBO drops NoOfSeatAvailable from the FareQuote response, but
            // Book wants it on every segment. Captured here — the last point it exists —
            // and carried to the booking so the Book payload can be assembled. Null on a
            // FareQuote-sourced itinerary, which is exactly why it must be carried.
            'seats' => ($seats = data_get($leg, 'NoOfSeatAvailable')) === null ? null : (int) $seats,
            'origin' => $this->mapPoint($leg, 'Origin', 'DepTime'),
            'destination' => $this->mapPoint($leg, 'Destination', 'ArrTime'),
        ];
    }

    /**
     * @param  array<string, mixed>  $leg
     * @return array{code: string, airport: string, terminal: string, city: string, time: ?string}
     */
    private function mapPoint(array $leg, string $side, string $timeKey): array
    {
        return [
            'code' => (string) data_get($leg, "$side.Airport.AirportCode", data_get($leg, "$side.Airport.CityCode", '')),
            'airport' => (string) data_get($leg, "$side.Airport.AirportName", ''),
            'terminal' => (string) data_get($leg, "$side.Airport.Terminal", ''),
            'city' => (string) data_get($leg, "$side.Airport.CityName", ''),
            'time' => data_get($leg, "$side.$timeKey"),
        ];
    }

    private function cabinLabel(int $code): string
    {
        return match ($code) {
            3 => 'Premium Economy',
            4 => 'Business',
            5 => 'Premium Business',
            6 => 'First',
            default => 'Economy',
        };
    }

    private function layover(mixed $arrival, mixed $departure): ?int
    {
        if (! $arrival || ! $departure) {
            return null;
        }

        $a = strtotime((string) $arrival);
        $d = strtotime((string) $departure);

        if ($a === false || $d === false) {
            return null;
        }

        return max((int) round(($d - $a) / 60), 0);
    }
}
