<?php

namespace App\Services\TboHotel\DTO;

use App\Support\SupplierHtml;
use Illuminate\Support\Arr;

/**
 * The binding answer to "may I book this, and at what price".
 *
 * §18: *"Cancellation Policy and Norms received in the PreBook response will be
 * considered as final for the booking itinerary."* So this — not Search — is what we
 * charge, store, display and later compute a refund from. Search's copy is a
 * shop-window price with no standing.
 *
 * A move between the searched price and this one is normal and expected. It is a gate
 * to put in front of the agent, never an error: the live reference implementation
 * treats it as a failure and loses the sale.
 *
 * One BookingCode is one bookable combination. On a two-room stay TBO answers with a
 * single room entry whose `names` holds both rooms and whose `totalFare` covers both —
 * measured, not assumed. There is no per-room total to reconcile.
 */
readonly class PreBookResult
{
    /**
     * @param  array<int, string>  $rateConditions
     * @param  array<int, string>  $amenities
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $hotelCode,
        public string $currency,
        public RoomOffer $room,
        public array $rateConditions,
        public array $amenities,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromResponse(array $body): self
    {
        $hotel = (array) (Arr::get($body, 'HotelResult.0') ?? []);
        $room = (array) (Arr::get($hotel, 'Rooms.0') ?? []);

        return new self(
            hotelCode: (string) Arr::get($hotel, 'HotelCode', ''),
            currency: (string) Arr::get($hotel, 'Currency', ''),
            room: RoomOffer::fromResponse($room),
            // RateConditions hangs off the hotel, not the room — the spec's wording
            // ("adds ... RateConditions" to the room object) does not match the wire.
            rateConditions: self::conditions(Arr::get($hotel, 'RateConditions')),
            amenities: self::strings(Arr::get($room, 'Amenities')),
            raw: $body,
        );
    }

    public function totalFare(): float
    {
        return $this->room->totalFare;
    }

    /**
     * Did the price move from what the agent was shown?
     *
     * Compared in whole cents. Two floats that print the same are not reliably `===`,
     * and a gate that fires on a rounding artefact trains agents to click past it.
     */
    public function priceChanged(float $searchedFare): bool
    {
        return $this->cents($this->totalFare()) !== $this->cents($searchedFare);
    }

    public function priceDelta(float $searchedFare): float
    {
        return round($this->totalFare() - $searchedFare, 2);
    }

    public function isBookable(): bool
    {
        return $this->room->bookingCode !== '' && $this->totalFare() > 0;
    }

    /**
     * For the browser. `raw` is deliberately absent: it is kept for the audit trail on
     * the booking row, and shipping it to the page would double the payload for data
     * nothing renders.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hotelCode' => $this->hotelCode,
            'currency' => $this->currency,
            'rateConditions' => $this->rateConditions,
            'amenities' => $this->amenities,
        ] + $this->room->toArray();
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * The hotel's norms, made readable.
     *
     * TBO sends several of these as HTML with the angle brackets already escaped, so
     * they arrive as the literal text `&lt;ul&gt;&lt;li&gt;…`. Printed as-is an agent
     * reads markup instead of the check-in rules; decoded and rendered raw, a supplier
     * gets to inject markup into a logged-in page. Decoded, then reduced to the same
     * allow-list the descriptions go through.
     *
     * Entries with no markup are common and pass through untouched.
     *
     * @return array<int, string>
     */
    private static function conditions(mixed $value): array
    {
        return array_values(array_filter(array_map(
            fn (string $condition): string => (string) SupplierHtml::clean(
                html_entity_decode($condition, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ),
            self::strings($value),
        ), fn (string $condition): bool => $condition !== ''));
    }

    /**
     * @return array<int, string>
     */
    private static function strings(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value) === '' ? [] : [$value];
        }

        return array_values(array_filter(array_map(
            fn ($item): string => trim((string) $item),
            is_array($value) ? $value : [],
        ), fn (string $item): bool => $item !== ''));
    }
}
