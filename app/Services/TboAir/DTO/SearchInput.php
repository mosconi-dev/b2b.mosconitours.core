<?php

namespace App\Services\TboAir\DTO;

use App\Enums\CabinClass;
use App\Enums\TripType;

class SearchInput
{
    /**
     * @param  array<int, array{origin: string, destination: string, departure: string}>  $segments
     */
    public function __construct(
        public readonly TripType $tripType,
        public readonly CabinClass $cabin,
        public readonly int $adults,
        public readonly int $children,
        public readonly int $infants,
        public readonly array $segments,
        public readonly ?string $returnDate = null,
    ) {}

    /**
     * Everyone on the booking, infants included.
     *
     * Infants count: a per-passenger markup that skipped them would under-price a
     * family, and whether they *should* be charged one is a pricing rule's decision,
     * not this DTO's.
     */
    public function passengerCount(): int
    {
        return $this->adults + $this->children + $this->infants;
    }

    /**
     * Canonical representation, used to derive a deterministic cache key.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tripType' => $this->tripType->value,
            'cabin' => $this->cabin->value,
            'adults' => $this->adults,
            'children' => $this->children,
            'infants' => $this->infants,
            'segments' => $this->segments,
            'returnDate' => $this->returnDate,
        ];
    }
}
