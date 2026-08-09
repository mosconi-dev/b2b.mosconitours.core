<?php

namespace App\Services\TboAir\DTO;

use App\Services\TboAir\FareTotal;
use App\Services\TboAir\ItineraryMapper;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * The binding re-price of a selected result (TBO FareQuote). The offered fare may
 * differ from the search fare (`isPriceChanged`); `isLcc` drives Book-vs-Ticket and
 * `isPassportMandatory` gates passenger collection.
 */
class FareQuote implements Arrayable, JsonSerializable
{
    /**
     * @param  array{currency: string, baseFare: float, tax: float, offeredFare: float, publishedFare: float}  $price
     * @param  array<int, array{passengerType: string, count: int, baseFare: float, tax: float}>  $fareBreakdown
     * @param  array<int, array{direction: string, stops: int, duration: int, segments: array<int, array<string, mixed>>}>  $trips
     * @param  array<int, array{type: string, details: string, journeyPoints: string, from: string, to: string, unit: string, onlineRefundAllowed: bool, onlineReissueAllowed: bool}>  $miniFareRules
     */
    public function __construct(
        public readonly string $resultIndex,
        public readonly ?string $traceId,
        public readonly bool $isLcc,
        public readonly bool $isRefundable,
        public readonly bool $isPriceChanged,
        public readonly bool $isPassportMandatory,
        public readonly array $price,
        public readonly array $fareBreakdown,
        public readonly array $trips = [],
        public readonly array $miniFareRules = [],
        public readonly ?string $baggage = null,
        public readonly ?string $cabinBaggage = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        $result = data_get($data, 'Response.Results', data_get($data, 'Results', []));

        // FareQuote returns a single result object; if a list slips through, take the first.
        if (is_array($result) && array_is_list($result)) {
            $result = $result[0] ?? [];
        }

        $fare = data_get($result, 'Fare', []);

        // FareQuote carries the full itinerary and a structured fee summary, so the
        // booking page can show the same detail as the results page — and the real
        // cancellation/reissue figures — without a second provider call.
        $itinerary = new ItineraryMapper;
        $trips = $itinerary->trips(data_get($result, 'Segments', []));
        $legs = $itinerary->legs($trips);

        return new self(
            resultIndex: (string) data_get($result, 'ResultIndex', ''),
            traceId: data_get($data, 'Response.TraceId', data_get($data, 'TraceId')),
            isLcc: (bool) data_get($result, 'IsLCC', false),
            isRefundable: (bool) data_get($result, 'IsRefundable', false),
            isPriceChanged: (bool) data_get($result, 'IsPriceChanged', false),
            isPassportMandatory: (bool) (
                data_get($result, 'IsPassportRequiredAtBook')
                ?? data_get($result, 'IsPassportRequiredAtTicket')
                ?? data_get($result, 'IsPassportFullDetailRequiredAtBook', false)
            ),
            price: [
                'currency' => (string) data_get($fare, 'Currency', 'PHP'),
                'baseFare' => (float) data_get($fare, 'BaseFare', 0),
                'tax' => (float) data_get($fare, 'Tax', 0),
                'offeredFare' => FareTotal::for((array) $result),
                'publishedFare' => (float) data_get($fare, 'PublishedFare', 0),
            ],
            fareBreakdown: array_map(fn (array $b): array => [
                'passengerType' => self::paxLabel((int) data_get($b, 'PassengerType', 0)),
                'count' => (int) data_get($b, 'PassengerCount', 0),
                'baseFare' => (float) data_get($b, 'BaseFare', 0),
                'tax' => (float) data_get($b, 'Tax', 0),
            ], array_values((array) data_get($result, 'FareBreakdown', []))),
            trips: $trips,
            miniFareRules: self::mapMiniFareRules(data_get($result, 'MiniFareRules', [])),
            baggage: $itinerary->lowestAllowance($legs, 'baggage'),
            cabinBaggage: $itinerary->lowestAllowance($legs, 'cabinBaggage'),
        );
    }

    /**
     * TBO's per-fare cancellation/reissue summary. Nested one list per journey like
     * Segments, so flatten it; entries are kept verbatim ("4466 PHP (Before)") rather
     * than parsed into numbers — the wording carries the condition.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function mapMiniFareRules(mixed $rules): array
    {
        if (! is_array($rules) || $rules === []) {
            return [];
        }

        if (ItineraryMapper::isNestedList($rules)) {
            $rules = array_merge([], ...array_map(
                fn (mixed $group): array => is_array($group) ? array_values(array_filter($group, 'is_array')) : [],
                $rules
            ));
        }

        return array_values(array_map(fn (array $r): array => [
            'type' => (string) data_get($r, 'Type', ''),
            'details' => (string) data_get($r, 'Details', ''),
            'journeyPoints' => (string) data_get($r, 'JourneyPoints', ''),
            'from' => (string) data_get($r, 'From', ''),
            'to' => (string) data_get($r, 'To', ''),
            'unit' => (string) data_get($r, 'Unit', ''),
            'onlineRefundAllowed' => (bool) data_get($r, 'OnlineRefundAllowed', false),
            'onlineReissueAllowed' => (bool) data_get($r, 'OnlineReissueAllowed', false),
        ], array_values(array_filter($rules, 'is_array'))));
    }

    private static function paxLabel(int $type): string
    {
        return match ($type) {
            1 => 'Adult',
            2 => 'Child',
            3 => 'Infant',
            default => 'Passenger',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'resultIndex' => $this->resultIndex,
            'traceId' => $this->traceId,
            'isLcc' => $this->isLcc,
            'isRefundable' => $this->isRefundable,
            'isPriceChanged' => $this->isPriceChanged,
            'isPassportMandatory' => $this->isPassportMandatory,
            'price' => $this->price,
            'fareBreakdown' => $this->fareBreakdown,
            'trips' => $this->trips,
            'miniFareRules' => $this->miniFareRules,
            'baggage' => $this->baggage,
            'cabinBaggage' => $this->cabinBaggage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
