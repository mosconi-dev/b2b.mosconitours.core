<?php

namespace App\Services\Pricing;

use App\Enums\BookingProduct;
use App\Enums\Supplier;
use App\Enums\TravelScope;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Builds a PricingContext from a product's payload.
 *
 * The ONLY place that knows a product's shape. The engine, the matcher and the
 * calculators read fields off PricingContext and never touch a flight or a hotel, which
 * is what lets transfers or tours arrive without the core changing — a new product is a
 * new method here.
 *
 * It reads the SERIALIZED arrays rather than the DTOs on purpose: those arrays are what
 * comes back out of the search caches, and the engine runs on the way out of the cache
 * so that a rule edited at 10:00 takes effect at once instead of after the TTL.
 */
class PricingContextFactory
{
    /**
     * The attribute keys each product's contexts actually carry.
     *
     * Declared here because this class is the only one that knows a product's shape, and
     * these are exactly the keys the methods below put on `attributes`. Keep the two in
     * step: a rule may match on anything in this list and on nothing outside it.
     *
     * **Why it is validated at all.** A matcher key the context never emits reads as
     * null, the comparison fails, and the rule quietly never fires. A rule that charges
     * nothing because of a typo is indistinguishable from one nobody wrote — so the
     * typo is caught in the form instead.
     *
     * @var array<string, array<int, string>>
     */
    public const MATCHABLE_KEYS = [
        'flight' => ['airline', 'cabin', 'isLcc', 'isRefundable', 'stops', 'origin', 'destination'],
        'hotel' => ['hotelCode', 'countryCode', 'cityCode', 'rating', 'isRefundable', 'mealType', 'withTransfers'],
    ];

    /**
     * What a rule on this product may match on.
     *
     * A rule matching every product gets the UNION rather than the intersection. A
     * `{"airline": "PR"}` matcher on an all-products rule is odd but not wrong — it
     * simply never fires on a hotel — and refusing it would be this validator deciding a
     * question it was not asked. What it still catches is the typo.
     *
     * @return array<int, string>
     */
    public static function matchableKeys(string $product): array
    {
        if (array_key_exists($product, self::MATCHABLE_KEYS)) {
            return self::MATCHABLE_KEYS[$product];
        }

        return array_values(array_unique(array_merge(...array_values(self::MATCHABLE_KEYS))));
    }

    /**
     * Every product's matchable keys, for a form that switches between them.
     *
     * @return array<string, array<int, string>>
     */
    public static function matchableKeysByProduct(): array
    {
        $products = array_merge(['*'], BookingProduct::values());

        return array_reduce(
            $products,
            fn (array $carry, string $product): array => $carry + [$product => self::matchableKeys($product)],
            [],
        );
    }

    /**
     * @param  array<string, mixed>  $offer  one entry of FlightOffer::toArray()
     */
    public function forFlightOffer(array $offer, string $currency = 'PHP', int $paxCount = 1): PricingContext
    {
        return new PricingContext(
            product: BookingProduct::Flight,
            supplier: Supplier::TboAir,
            scope: $this->scope($offer['scope'] ?? null),
            net: NetPrice::of(Arr::get($offer, 'price.offeredFare', 0)),
            currency: (string) Arr::get($offer, 'price.currency', $currency),
            paxCount: max(1, $paxCount),
            travelDate: $this->date(Arr::get($offer, 'departure.time')),
            attributes: [
                'airline' => Arr::get($offer, 'airlineCode'),
                'cabin' => Arr::get($offer, 'cabin'),
                'isLcc' => (bool) Arr::get($offer, 'isLcc', false),
                'isRefundable' => (bool) Arr::get($offer, 'isRefundable', false),
                'stops' => (int) Arr::get($offer, 'stops', 0),
                'origin' => Arr::get($offer, 'departure.code'),
                'destination' => Arr::get($offer, 'arrival.code'),
            ],
            baseFare: Money::of(Arr::get($offer, 'price.baseFare', 0)),
            ancillaries: $this->ancillaries($offer),
        );
    }

    /**
     * @param  array<string, mixed>  $quote  FareQuote::toArray()
     */
    public function forFareQuote(array $quote): PricingContext
    {
        $trip = Arr::get($quote, 'trips.0.segments.0', []);

        return new PricingContext(
            product: BookingProduct::Flight,
            supplier: Supplier::TboAir,
            scope: $this->scope($quote['scope'] ?? null),
            net: NetPrice::of(Arr::get($quote, 'price.offeredFare', 0)),
            currency: (string) Arr::get($quote, 'price.currency', 'PHP'),
            paxCount: max(1, array_sum(array_column((array) Arr::get($quote, 'fareBreakdown', []), 'count'))),
            travelDate: $this->date(Arr::get($trip, 'origin.time')),
            attributes: [
                'airline' => Arr::get($trip, 'airlineCode'),
                'cabin' => Arr::get($trip, 'cabin'),
                'isLcc' => (bool) Arr::get($quote, 'isLcc', false),
                'isRefundable' => (bool) Arr::get($quote, 'isRefundable', false),
                // Counted from the segments rather than read from a field, because the
                // quote has no `stops` of its own — and the SEARCH context has one. A
                // rule narrowed on stops that matched at search and missed here would
                // move the price between the two, which is the one failure this module
                // must not have.
                'stops' => max(0, count((array) Arr::get($quote, 'trips.0.segments', [])) - 1),
                'origin' => Arr::get($trip, 'origin.code'),
                'destination' => Arr::get($quote, 'trips.0.segments.'.(count((array) Arr::get($quote, 'trips.0.segments', [])) - 1).'.destination.code'),
            ],
            baseFare: Money::of(Arr::get($quote, 'price.baseFare', 0)),
            ancillaries: $this->ancillaries($quote),
        );
    }

    /**
     * The part of net that is bought-on-the-side rather than fare — baggage, meals.
     *
     * Null, not zero, when the payload does not carry the figure: a rule written on
     * `excl_ancillaries` must fall back to the whole net rather than silently price a
     * fare as if all of it were add-ons.
     *
     * @param  array<string, mixed>  $payload
     */
    private function ancillaries(array $payload): ?Money
    {
        $ancillaries = Arr::get($payload, 'price.ancillaries');

        return $ancillaries === null ? null : Money::of($ancillaries);
    }

    /**
     * One room rate, in the context of the property and the stay it belongs to.
     *
     * @param  array<string, mixed>  $offer  one entry of HotelOffer::toArray()
     * @param  array<string, mixed>  $room  one of that offer's rooms
     */
    public function forHotelRoom(array $offer, array $room, int $nights = 1, int $rooms = 1, ?string $checkIn = null): PricingContext
    {
        return new PricingContext(
            product: BookingProduct::Hotel,
            supplier: Supplier::TboHotel,
            scope: $this->scope($offer['scope'] ?? null),
            net: NetPrice::of($room['totalFare'] ?? 0),
            currency: (string) ($offer['currency'] ?? 'PHP'),
            roomCount: max(1, $rooms),
            nights: max(1, $nights),
            travelDate: $this->date($checkIn),
            attributes: [
                'hotelCode' => $offer['hotelCode'] ?? null,
                'countryCode' => $offer['countryCode'] ?? null,
                'cityCode' => $offer['cityCode'] ?? null,
                'rating' => $offer['rating'] ?? null,
                'isRefundable' => (bool) ($room['isRefundable'] ?? false),
                'mealType' => $room['mealType'] ?? null,
                'withTransfers' => (bool) ($room['withTransfers'] ?? false),
            ],
        );
    }

    /**
     * A missing or unrecognised scope reads as international, the same convention the
     * classifier itself uses — unknown must never be the cheaper answer.
     */
    private function scope(mixed $value): TravelScope
    {
        return TravelScope::tryFrom((string) $value) ?? TravelScope::International;
    }

    private function date(mixed $value): ?Carbon
    {
        return blank($value) ? null : Carbon::parse((string) $value);
    }
}
