<?php

namespace App\Services\TboAir\DTO;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class FlightOffer implements Arrayable, JsonSerializable
{
    /**
     * @param  array<int, string>  $flightNumbers
     * @param  array{code: string, city: string, time: ?string}  $departure
     * @param  array{code: string, city: string, time: ?string}  $arrival
     * @param  array{currency: string, baseFare: float, tax: float, offeredFare: float, publishedFare: float}  $price
     * @param  array<int, array<string, mixed>>  $trips
     */
    public function __construct(
        public readonly string $resultIndex,
        public readonly int $source,
        public readonly bool $isLcc,
        public readonly bool $isRefundable,
        public readonly string $airlineCode,
        public readonly string $airlineName,
        public readonly array $flightNumbers,
        public readonly string $cabin,
        public readonly int $stops,
        public readonly int $duration,
        public readonly ?string $baggage,
        public readonly ?string $cabinBaggage,
        public readonly array $departure,
        public readonly array $arrival,
        public readonly array $price,
        public readonly array $trips,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'resultIndex' => $this->resultIndex,
            'source' => $this->source,
            'isLcc' => $this->isLcc,
            'isRefundable' => $this->isRefundable,
            'airlineCode' => $this->airlineCode,
            'airlineName' => $this->airlineName,
            'flightNumbers' => $this->flightNumbers,
            'cabin' => $this->cabin,
            'stops' => $this->stops,
            'duration' => $this->duration,
            'baggage' => $this->baggage,
            'cabinBaggage' => $this->cabinBaggage,
            'departure' => $this->departure,
            'arrival' => $this->arrival,
            'price' => $this->price,
            'trips' => $this->trips,
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
