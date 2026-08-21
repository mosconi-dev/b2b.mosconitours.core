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
