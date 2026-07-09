<?php

namespace App\Services\TboAir\DTO;

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
                'offeredFare' => (float) (data_get($fare, 'OfferedFare') ?: data_get($fare, 'PublishedFare', 0)),
                'publishedFare' => (float) data_get($fare, 'PublishedFare', 0),
            ],
            fareBreakdown: array_map(fn (array $b): array => [
                'passengerType' => self::paxLabel((int) data_get($b, 'PassengerType', 0)),
                'count' => (int) data_get($b, 'PassengerCount', 0),
                'baseFare' => (float) data_get($b, 'BaseFare', 0),
                'tax' => (float) data_get($b, 'Tax', 0),
            ], array_values((array) data_get($result, 'FareBreakdown', []))),
        );
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
