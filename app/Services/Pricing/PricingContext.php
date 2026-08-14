<?php

namespace App\Services\Pricing;

use App\Enums\BookingProduct;
use App\Enums\Supplier;
use App\Enums\TravelScope;
use Illuminate\Support\Carbon;

/**
 * Everything a pricing rule may match on, for one thing being priced.
 *
 * Built once by PricingContextFactory, which is the only code that knows a product's
 * DTO shape. The engine, the matcher and the calculators read fields off this and never
 * touch a FlightOffer or a HotelOffer — that is what keeps product logic out of the
 * core and lets transfers or tours arrive without the engine changing.
 *
 * Product-specific fields live in `attributes` rather than as typed properties for the
 * same reason: the matchable set differs per product and grows with each new one, and
 * typed properties would mean editing this class every time.
 */
final readonly class PricingContext
{
    /**
     * @param  array<string, scalar|null>  $attributes  airline, cabin, isLcc, rating, city…
     */
    public function __construct(
        public BookingProduct $product,
        public Supplier $supplier,
        public TravelScope $scope,
        public NetPrice $net,
        public string $currency = 'PHP',
        public int $paxCount = 1,
        public int $roomCount = 1,
        public int $nights = 1,
        public ?Carbon $travelDate = null,
        public ?Carbon $bookingDate = null,
        public array $attributes = [],
        /**
         * The part of net a percentage rule may be told to work from — set by the
         * factory, because only it knows how a product splits its fare.
         */
        public ?Money $baseFare = null,
        public ?Money $ancillaries = null,
    ) {}

    /**
     * When the booking is being made. Defaults to now rather than null so a rule with a
     * booking-date window always has something to compare against.
     */
    public function bookedOn(): Carbon
    {
        return $this->bookingDate ?? Carbon::now();
    }

    public function attribute(string $key): string|int|float|bool|null
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * A rule's `applies_to` resolved against this context.
     *
     * Falls back to the full net whenever the narrower figure is unknown: pricing a
     * fare at zero because its base fare could not be read would be a silent revenue
     * loss, where pricing it on the total is merely the broader of two defensible bases.
     */
    public function basisFor(string $appliesTo): Money
    {
        return match ($appliesTo) {
            'base_fare' => $this->baseFare ?? $this->net->amount,
            'excl_ancillaries' => $this->ancillaries === null
                ? $this->net->amount
                : $this->net->amount->minus($this->ancillaries),
            default => $this->net->amount,
        };
    }

    /**
     * For the ladder preview and for debugging a quote.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product' => $this->product->value,
            'supplier' => $this->supplier->value,
            'scope' => $this->scope->value,
            'net' => (string) $this->net->amount,
            'currency' => $this->currency,
            'paxCount' => $this->paxCount,
            'roomCount' => $this->roomCount,
            'nights' => $this->nights,
            'travelDate' => $this->travelDate?->toDateString(),
            'bookingDate' => $this->bookedOn()->toDateString(),
            'attributes' => $this->attributes,
        ];
    }
}
