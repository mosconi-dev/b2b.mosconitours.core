<?php

namespace App\Services\TboHotel\DTO;

/**
 * What a city search produced, and how much of it it managed to cover.
 *
 * `chunksFailed` is the point. A city is many calls, any of which can be throttled
 * or time out, and a results page that quietly shows nine tenths of a city looks
 * exactly like one that shows all of it. The count travels with the offers so the
 * page can say so.
 */
readonly class SearchResult
{
    /**
     * @param  array<int, HotelOffer>  $offers
     */
    public function __construct(
        public array $offers,
        public string $currency,
        public int $hotelsSearched,
        public int $chunks,
        public int $chunksFailed = 0,
    ) {}

    public function isPartial(): bool
    {
        return $this->chunksFailed > 0;
    }

    public function isEmpty(): bool
    {
        return $this->offers === [];
    }

    /**
     * Roughly how many properties went unchecked, for telling the agent plainly.
     */
    public function hotelsMissed(): int
    {
        return $this->chunks === 0
            ? 0
            : (int) round($this->hotelsSearched * ($this->chunksFailed / $this->chunks));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'offers' => array_map(fn (HotelOffer $o): array => $o->toArray(), $this->offers),
            'currency' => $this->currency,
            'hotelsSearched' => $this->hotelsSearched,
            'chunks' => $this->chunks,
            'chunksFailed' => $this->chunksFailed,
            'partial' => $this->isPartial(),
            'hotelsMissed' => $this->hotelsMissed(),
        ];
    }
}
