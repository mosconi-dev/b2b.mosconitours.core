<?php

namespace App\Services\TboAir;

use App\Services\TboAir\DTO\FlightOffer;
use Illuminate\Support\Arr;

class FlightResultTransformer
{
    public function __construct(
        private readonly ItineraryMapper $itinerary = new ItineraryMapper,
    ) {}

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
        if (ItineraryMapper::isNestedList($results)) {
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
     * @param  array<string, mixed>  $raw
     */
    private function mapOffer(array $raw): FlightOffer
    {
        $trips = $this->itinerary->trips(data_get($raw, 'Segments', []));

        $outbound = $trips[0]['segments'] ?? [];
        $firstLeg = $outbound[0] ?? [];
        $lastLeg = empty($outbound) ? [] : $outbound[array_key_last($outbound)];

        $flightNumbers = array_values(array_filter(array_map(
            fn (array $leg): ?string => $leg['flightNumber'] ?? null,
            $outbound
        )));

        $allLegs = $this->itinerary->legs($trips);

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
            baggage: $this->itinerary->lowestAllowance($allLegs, 'baggage'),
            cabinBaggage: $this->itinerary->lowestAllowance($allLegs, 'cabinBaggage'),
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
}
