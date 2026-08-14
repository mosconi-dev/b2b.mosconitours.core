<?php

namespace App\Services\Pricing;

use App\Models\User;

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
            $offer['pricing'] = $breakdown->forViewer($viewer);

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
        $quote['pricing'] = $breakdown->forViewer($viewer);

        return $quote;
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
            $room['pricing'] = $breakdown->forViewer($viewer);

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
        if ($rows === []) {
            return $rows;
        }

        $allocated = Money::zero();
        $last = array_key_last($rows);

        foreach ($rows as $i => $row) {
            $count = max(1, (int) ($row['count'] ?? 1));

            // A FareBreakdown row is the GROUP total for that passenger type — a row of
            // three adults already holds the fare for all three, which is why summing
            // the rows reproduces the trip total (see FareTotal). Multiplying by `count`
            // here would price the group twice.
            $rowNet = Money::of($row['baseFare'] ?? 0)->plus(Money::of($row['tax'] ?? 0));

            if ($i === $last) {
                // Whatever is left, so the rows reconcile to the total exactly.
                $rowSell = $sell->minus($allocated);
            } else {
                $share = $net->isZero() ? Money::zero() : $rowNet->times(bcdiv((string) $sell, (string) $net, 6));
                $rowSell = $share;
                $allocated = $allocated->plus($share);
            }

            $rows[$i]['amount'] = $rowSell->times(bcdiv('1', (string) $count, 6))->toFloat();
            $rows[$i]['amountTotal'] = $rowSell->toFloat();

            if (! $viewer->isPlatformStaff()) {
                unset($rows[$i]['baseFare'], $rows[$i]['tax']);
            }
        }

        return array_values($rows);
    }
}
