<?php

namespace App\Services\TboHotel\DTO;

use App\Models\Hotel;
use Illuminate\Support\Arr;

/**
 * One property with everything bookable in it for this stay, joined to what we
 * know about the hotel locally.
 *
 * TBO's Search answers with a hotel code and rates and nothing else — no name, no
 * address, no photograph. Every human-readable part of a result card comes from the
 * catalogue, which is the second reason Phase 2 exists.
 */
readonly class HotelOffer
{
    /**
     * @param  array<int, RoomOffer>  $rooms
     */
    public function __construct(
        public string $hotelCode,
        public string $currency,
        public array $rooms,
        public ?Hotel $hotel = null,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromResponse(array $raw, ?Hotel $hotel = null): self
    {
        $rooms = array_map(
            fn ($room): RoomOffer => RoomOffer::fromResponse((array) $room),
            (array) Arr::get($raw, 'Rooms', []),
        );

        // Cheapest first: it is the number the card shows and the order the list
        // defaults to, so sorting once here saves every caller doing it.
        usort($rooms, fn (RoomOffer $a, RoomOffer $b): int => $a->totalFare <=> $b->totalFare);

        return new self(
            hotelCode: (string) Arr::get($raw, 'HotelCode', ''),
            currency: (string) Arr::get($raw, 'Currency', ''),
            rooms: $rooms,
            hotel: $hotel,
        );
    }

    public function cheapest(): ?RoomOffer
    {
        return $this->rooms[0] ?? null;
    }

    public function lowestFare(): float
    {
        return $this->cheapest()?->totalFare ?? 0.0;
    }

    public function name(): string
    {
        return $this->hotel?->name ?? "Hotel {$this->hotelCode}";
    }

    public function hasRefundable(): bool
    {
        return collect($this->rooms)->contains(fn (RoomOffer $r): bool => $r->isRefundable);
    }

    public function hasBreakfast(): bool
    {
        return collect($this->rooms)->contains(fn (RoomOffer $r): bool => $r->includesBreakfast());
    }

    public function hasTransfers(): bool
    {
        return collect($this->rooms)->contains(fn (RoomOffer $r): bool => $r->withTransfers);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hotelCode' => $this->hotelCode,
            'currency' => $this->currency,
            'name' => $this->name(),
            'address' => $this->hotel?->address,
            'rating' => $this->hotel?->rating,
            'latitude' => $this->hotel?->latitude,
            'longitude' => $this->hotel?->longitude,
            'thumbnail' => $this->hotel?->thumbnail(),
            'lowestFare' => $this->lowestFare(),
            'hasRefundable' => $this->hasRefundable(),
            'hasBreakfast' => $this->hasBreakfast(),
            'hasTransfers' => $this->hasTransfers(),
            'roomCount' => count($this->rooms),
            'rooms' => array_map(fn (RoomOffer $r): array => $r->toArray(), $this->rooms),
        ];
    }
}
