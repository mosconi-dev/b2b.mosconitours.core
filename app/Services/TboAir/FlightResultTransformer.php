<?php

namespace App\Services\TboAir;

use App\Services\TboAir\DTO\FlightOffer;
use Illuminate\Support\Arr;

class FlightResultTransformer
{
    /**
     * Normalize a raw TBO Air search response into FlightOffer objects.
     * Parses defensively: the outer envelope and segment nesting vary, and
     * missing keys must never fatal.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, FlightOffer>
     */
    public function transform(array $data): array
    {
        $results = data_get($data, 'Response.Results', data_get($data, 'Results', []));

        if (! is_array($results)) {
            return [];
        }

        // Results may be an array-of-arrays (one inner list per fare group).
        if ($this->isNestedList($results)) {
            $results = Arr::collapse($results);
        }

        $offers = [];

        foreach ($results as $raw) {
            if (is_array($raw)) {
                $offers[] = $this->mapOffer($raw);
            }
        }

        return $offers;
    }

    /**
     * @param  array<int|string, mixed>  $items
     */
    private function isNestedList(array $items): bool
    {
        $first = Arr::first($items);

        // A single result is an associative array; a nested results/segments
        // container has plain (list) arrays as its elements.
        return is_array($first) && array_is_list($first);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function mapOffer(array $raw): FlightOffer
    {
        $trips = $this->mapTrips(data_get($raw, 'Segments', []));

        $outbound = $trips[0]['segments'] ?? [];
        $firstLeg = $outbound[0] ?? [];
        $lastLeg = empty($outbound) ? [] : $outbound[array_key_last($outbound)];

        $flightNumbers = array_values(array_filter(array_map(
            fn (array $leg): ?string => $leg['flightNumber'] ?? null,
            $outbound
        )));

        $allLegs = array_merge([], ...array_map(
            fn (array $trip): array => $trip['segments'],
            $trips
        ));

        return new FlightOffer(
            resultIndex: (string) data_get($raw, 'ResultIndex', ''),
            source: (int) data_get($raw, 'Source', 0),
            isLcc: (bool) data_get($raw, 'IsLCC', false),
            isRefundable: (bool) data_get($raw, 'IsRefundable', false),
            airlineCode: (string) data_get($firstLeg, 'airlineCode', ''),
            airlineName: (string) data_get($firstLeg, 'airlineName', ''),
            flightNumbers: $flightNumbers,
            cabin: (string) data_get($firstLeg, 'cabin', ''),
            stops: (int) ($trips[0]['stops'] ?? max(count($outbound) - 1, 0)),
            duration: (int) ($trips[0]['duration'] ?? 0),
            baggage: $this->lowestAllowance($allLegs, 'baggage'),
            cabinBaggage: $this->lowestAllowance($allLegs, 'cabinBaggage'),
            departure: [
                'code' => (string) data_get($firstLeg, 'origin.code', ''),
                'city' => (string) data_get($firstLeg, 'origin.city', ''),
                'time' => data_get($firstLeg, 'origin.time'),
            ],
            arrival: [
                'code' => (string) data_get($lastLeg, 'destination.code', ''),
                'city' => (string) data_get($lastLeg, 'destination.city', ''),
                'time' => data_get($lastLeg, 'destination.time'),
            ],
            price: $this->mapFare($raw),
            trips: $trips,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{currency: string, baseFare: float, tax: float, offeredFare: float, publishedFare: float}
     */
    private function mapFare(array $raw): array
    {
        $fare = data_get($raw, 'Fare', []);

        return [
            'currency' => (string) data_get($fare, 'Currency', 'PHP'),
            'baseFare' => (float) data_get($fare, 'BaseFare', 0),
            'tax' => (float) data_get($fare, 'Tax', 0),
            'offeredFare' => FareTotal::for($raw),
            'publishedFare' => (float) data_get($fare, 'PublishedFare', 0),
        ];
    }

    /**
     * Normalize the Segments structure (nested-per-direction, or flat with
     * TripIndicator) into trips of legs.
     *
     * @return array<int, array{direction: string, stops: int, duration: int, segments: array<int, array<string, mixed>>}>
     */
    private function mapTrips(mixed $segments): array
    {
        if (! is_array($segments) || $segments === []) {
            return [];
        }

        $groups = [];

        if ($this->isNestedList($segments)) {
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
     * The smallest baggage allowance across every leg, so the summary badge never
     * promises more than the whole itinerary guarantees (a 20 KG first leg can be
     * followed by a 0 KG LCC connection).
     *
     * TBO returns free text ("20 KG", "2 Piece"), so we only compare legs that share
     * a unit; anything unparseable or mixed falls back to the first leg's own wording
     * rather than inventing a number.
     *
     * @param  array<int, array<string, mixed>>  $legs
     */
    private function lowestAllowance(array $legs, string $key): ?string
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
