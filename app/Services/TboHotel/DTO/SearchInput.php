<?php

namespace App\Services\TboHotel\DTO;

use Illuminate\Support\Carbon;

/**
 * One hotel availability request, before it is split into chunks.
 *
 * `location` is either a city (whose hotels we look up locally) or a single
 * property — TBO's Search takes hotel codes either way, so the distinction lives
 * here and disappears by the time a payload is built.
 */
readonly class SearchInput
{
    /**
     * @param  array<int, PaxRoom>  $rooms
     */
    public function __construct(
        public string $checkIn,
        public string $checkOut,
        public array $rooms,
        public string $guestNationality,
        public string $locationType,   // city | hotel
        public string $locationCode,
        public bool $refundableOnly = false,
        public string $mealType = 'All',
    ) {}

    public function nights(): int
    {
        return max(1, (int) Carbon::parse($this->checkIn)->diffInDays(Carbon::parse($this->checkOut)));
    }

    public function roomCount(): int
    {
        return count($this->rooms);
    }

    public function guests(): int
    {
        return array_sum(array_map(fn (PaxRoom $r): int => $r->guests(), $this->rooms));
    }

    public function isCitySearch(): bool
    {
        return $this->locationType === 'city';
    }

    /**
     * The criteria TBO needs, minus the hotel codes — those are added per chunk.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'CheckIn' => $this->checkIn,
            'CheckOut' => $this->checkOut,
            'GuestNationality' => strtoupper($this->guestNationality),
            'PaxRooms' => array_map(fn (PaxRoom $r): array => $r->toPayload(), $this->rooms),
            'ResponseTime' => (float) config('tbohotel.response_time', 20.0),
            'IsDetailedResponse' => (bool) config('tbohotel.search_detailed', true),
            'Filters' => [
                'Refundable' => $this->refundableOnly,
                'NoOfRooms' => $this->roomCount(),
                'MealType' => $this->mealType,
            ],
        ];
    }

    /**
     * Identifies this search for caching. Everything that changes the price is in
     * it — including nationality, which TBO warns changes what a room costs.
     */
    public function fingerprint(): string
    {
        return md5(json_encode([
            $this->checkIn, $this->checkOut, $this->guestNationality,
            $this->locationType, $this->locationCode,
            array_map(fn (PaxRoom $r): array => $r->toArray(), $this->rooms),
            $this->refundableOnly, $this->mealType,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'checkIn' => $this->checkIn,
            'checkOut' => $this->checkOut,
            'rooms' => array_map(fn (PaxRoom $r): array => $r->toArray(), $this->rooms),
            'guestNationality' => $this->guestNationality,
            'locationType' => $this->locationType,
            'locationCode' => $this->locationCode,
            'refundableOnly' => $this->refundableOnly,
            'mealType' => $this->mealType,
        ];
    }
}
