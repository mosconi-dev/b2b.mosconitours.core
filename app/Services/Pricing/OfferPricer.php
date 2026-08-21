<?php

namespace App\Services\Pricing;

use App\Models\User;
use Illuminate\Support\Arr;

/**
 * The one place the engine meets a product payload on the way to a browser.
 *
 * Runs AFTER the search cache, never inside it — the caches hold supplier net, so a
 * rule edited at 10:00 takes effect on the next search rather than when the TTL
 * expires, and a cached payload can never be a marked-up one that gets marked up again.
 *
 * Two jobs, and the second matters as much as the first:
 *
 *   1. Rewrite the headline price to the selling price, in the exact fields the pages
 *      already read, so the list, the sort, the filters and the wizard all move together.
 *   2. STRIP the fields that would let the viewer work backwards to the supplier net.
 *      An agency seeing `baseFare`, `tax` and `publishedFare` beside a sell price can
 *      reconstruct our cost in one subtraction, so those come out of the payload
 *      entirely for anyone not entitled to them.
 */
class OfferPricer
{
    public function __construct(
        private readonly PricingEngine $engine,
        private readonly PricingContextFactory $contexts,
    ) {}

    /**
     * Price a flight search payload in place.
     *
     * @param  array<string, mixed>  $payload  the cached {results, traceId, currency, …}
     * @return array<string, mixed>
     */
    public function flightSearch(array $payload, User $viewer, int $paxCount = 1): array
    {
        $currency = (string) ($payload['currency'] ?? 'PHP');

        $payload['results'] = array_map(function (array $offer) use ($viewer, $currency, $paxCount): array {
            $breakdown = $this->engine->quoteOrNet(
                $this->contexts->forFlightOffer($offer, $currency, $paxCount),
                $viewer->agency,
            );

            $offer['price']['offeredFare'] = $breakdown->sell->amount->toFloat();
            $offer['price'] = $this->redactFare($offer['price'], $viewer);
            $offer['pricing'] = AgencyPriceView::forOffer($breakdown, $viewer)->toArray();

            return $offer;
        }, (array) ($payload['results'] ?? []));

        return $payload;
    }

    /**
     * Price one FareQuote payload — the binding re-price the wizard shows.
     *
     * @param  array<string, mixed>  $quote  FareQuote::toArray()
     * @return array<string, mixed>
     */
    public function fareQuote(array $quote, User $viewer): array
    {
        $breakdown = $this->engine->quoteOrNet($this->contexts->forFareQuote($quote), $viewer->agency);

        $net = $breakdown->net->amount;
        $sell = $breakdown->sell->amount;

        $quote['fareBreakdown'] = $this->allocateBreakdown((array) ($quote['fareBreakdown'] ?? []), $net, $sell, $viewer);
        $quote['price']['offeredFare'] = $sell->toFloat();
        $quote['price'] = $this->redactFare($quote['price'], $viewer);
        $quote['pricing'] = AgencyPriceView::forOffer($breakdown, $viewer)->toArray();

        return $quote;
    }

    /**
     * Price the add-on menu — every baggage and meal option the wizard offers.
     *
     * These arrive from TBO at SUPPLIER NET, and reached the browser untouched: the
     * pickers listed our cost for a bag, and the payment step added that cost to an
     * already-priced fare, so the agent approved a total lower than the one the
     * booking was written at. Both halves of that are fixed here.
     *
     * Each option is priced at its MARGINAL selling price — what the total goes up by
     * when this option is added — because that is literally how the charge is formed:
     * BookingService folds ancillaries into net and prices the sum once. Taking the
     * difference rather than pricing the option on its own keeps flat rules out of it,
     * so a ₱250 service fee is charged once for the booking and not once per meal.
     *
     * @param  array<string, mixed>  $ssr  Ssr::toArray()
     * @param  array<string, mixed>  $quote  the FareQuote::toArray() these belong to
     * @return array<string, mixed>
     */
    public function ssr(array $ssr, array $quote, User $viewer): array
    {
        $fare = $this->engine
            ->quoteOrNet($this->contexts->forFareQuote($quote), $viewer->agency)
            ->sell->amount;

        foreach (['baggage', 'meals'] as $kind) {
            $ssr[$kind] = array_map(
                fn (array $option): array => $this->priceOption($option, $quote, $fare, $viewer),
                (array) ($ssr[$kind] ?? []),
            );
        }

        return $ssr;
    }

    /**
     * One add-on at the price the agency pays for it.
     *
     * A free option stays free: TBO sends complimentary meals at 0.00, and a
     * percentage of nothing is nothing, so no special case is needed for them.
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private function priceOption(array $option, array $quote, Money $fare, User $viewer): array
    {
        $net = Money::of($option['price'] ?? 0);

        $quote['price']['offeredFare'] = (string) Money::of(Arr::get($quote, 'price.offeredFare', 0))->plus($net);
        // Declared so a rule written on `excl_ancillaries` can exclude exactly this
        // option, which is the only reason that basis exists.
        $quote['price']['ancillaries'] = (string) $net;

        $withOption = $this->engine
            ->quoteOrNet($this->contexts->forFareQuote($quote), $viewer->agency)
            ->sell->amount;

        $option['price'] = $withOption->minus($fare)->toFloat();

        return $option;
    }

    /**
     * Price a hotel search payload in place — every room of every property.
     *
     * @param  array<string, mixed>  $payload  the cached SearchResult::toArray()
     * @return array<string, mixed>
     */
    public function hotelSearch(array $payload, User $viewer, int $nights = 1, int $rooms = 1, ?string $checkIn = null): array
    {
        $payload['offers'] = array_map(
            fn (array $offer): array => $this->hotelOffer($offer, $viewer, $nights, $rooms, $checkIn),
            (array) ($payload['offers'] ?? []),
        );

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $offer
     * @return array<string, mixed>
     */
    public function hotelOffer(array $offer, User $viewer, int $nights = 1, int $rooms = 1, ?string $checkIn = null): array
    {
        $offer['rooms'] = array_map(function (array $room) use ($offer, $viewer, $nights, $rooms, $checkIn): array {
            $breakdown = $this->engine->quoteOrNet(
                $this->contexts->forHotelRoom($offer, $room, $nights, $rooms, $checkIn),
                $viewer->agency,
            );

            // Recomputed from the selling price BEFORE dayRates goes, and only when the
            // supplier priced the nights evenly — the original's null means "the nights
            // differ", and an averaged rate is a number the agent would have to defend
            // and could not.
            $room['nightlyRate'] = ($room['nightlyRate'] ?? null) === null
                ? null
                : round($breakdown->sell->amount->toFloat() / max(1, $nights * $rooms), 2);

            $room['totalFare'] = $breakdown->sell->amount->toFloat();
            $room['pricing'] = AgencyPriceView::forOffer($breakdown, $viewer)->toArray();

            // The per-night base prices sum to the net fare, so they hand over our cost
            // in one addition. `totalTax` stays: it is a component, not a total, and the
            // selling price genuinely does include it.
            unset($room['dayRates']);

            return $room;
        }, (array) ($offer['rooms'] ?? []));

        // The card's headline is the cheapest room, which has just moved.
        $offer['lowestFare'] = collect($offer['rooms'])->min('totalFare') ?? 0.0;

        return $offer;
    }

    /**
     * Price a PreBook result — the binding rate the hotel wizard shows.
     *
     * PreBookResult::toArray() merges the room's own array, so it is the same shape a
     * room on the results page has and prices through the same path. That is the point:
     * a rule keyed on star rating or city must give the same answer on both screens, or
     * the wizard opens on a price-change gate that nothing but our own arithmetic caused.
     *
     * @param  array<string, mixed>  $quote  PreBookResult::toArray()
     * @param  array<string, mixed>  $property  hotelCode, countryCode, cityCode, rating, scope
     * @return array<string, mixed>
     */
    public function preBookQuote(array $quote, array $property, User $viewer, int $nights = 1, int $rooms = 1, ?string $checkIn = null): array
    {
        $priced = $this->hotelOffer(
            $property + ['currency' => $quote['currency'] ?? 'PHP', 'rooms' => [$quote]],
            $viewer,
            $nights,
            $rooms,
            $checkIn,
        );

        // Merge the priced room back over the quote, so rateConditions and amenities —
        // which are the quote's own, not the room's — survive.
        return array_replace($quote, $priced['rooms'][0] ?? []);
    }

    /**
     * The supplier's own fare components, kept only for someone entitled to the net.
     *
     * @param  array<string, mixed>  $price
     * @return array<string, mixed>
     */
    private function redactFare(array $price, User $viewer): array
    {
        if ($viewer->isPlatformStaff()) {
            return $price;
        }

        unset($price['baseFare'], $price['tax'], $price['publishedFare']);

        return $price;
    }

    /**
     * Spread the markup across the per-passenger-type rows.
     *
     * The wizard shows a row per passenger type, and left at supplier figures those rows
     * would not sum to the price beside them — which reads as a broken page long before
     * anyone works out it is a net figure next to a sell one.
     *
     * Allocated in proportion to each row's share of net, with the LAST row absorbing
     * the rounding remainder so the rows always sum to the total exactly.
     *
     * `amount` is a per-passenger SELLING price and is labelled as an allocation, not as
     * anything the supplier said. `baseFare` and `tax` come out for anyone not entitled
     * to the net.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function allocateBreakdown(array $rows, Money $net, Money $sell, User $viewer): array
    {
        $rows = FareAllocation::allocate($rows, $net, $sell);

        return $viewer->isPlatformStaff() ? $rows : FareAllocation::redact($rows);
    }
}
