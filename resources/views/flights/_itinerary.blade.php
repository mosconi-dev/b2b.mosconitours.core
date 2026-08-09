{{-- Leg-by-leg itinerary for one offer/quote.

     Rendered by both the search results (offer.trips) and the booking wizard's
     flight-details toggle (quote.trips) — FareQuote returns the same trip shape
     as Search, so the two views can't drift apart.

     @param string $trips  Alpine expression resolving to the trips array.

     Needs formatTime/formatDuration on the surrounding Alpine scope. --}}
<template x-for="(trip, ti) in {{ $trips }}" :key="ti">
    <div>
        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400" x-text="trip.direction === 'inbound' ? 'Return' : 'Outbound'"></p>
        <template x-for="(leg, li) in trip.segments" :key="li">
            <div>
                <div class="flex gap-3 rounded-lg bg-white p-3 ring-1 ring-gray-100">
                    <div class="flex flex-col items-center pt-1">
                        <span class="h-2 w-2 rounded-full border-2 border-brand-700"></span>
                        <span class="my-1 w-px flex-1 bg-gray-200"></span>
                        <span class="h-2 w-2 rounded-full bg-brand-700"></span>
                    </div>
                    <div class="flex-1 space-y-2 text-sm">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="font-semibold text-brand-900" x-text="formatTime(leg.origin.time)"></span>
                            <span class="truncate text-right text-gray-600">
                                <span class="font-medium" x-text="leg.origin.code"></span>
                                <span x-text="leg.origin.airport || leg.origin.city"></span><span x-show="leg.origin.terminal" x-text="' · T' + leg.origin.terminal"></span>
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-400">
                            <span x-text="leg.flightNumber"></span>
                            <span>·</span>
                            <span x-text="formatDuration(leg.duration)"></span>
                            <span>·</span>
                            <span x-text="leg.cabin"></span>
                            <span x-show="leg.baggage">·</span>
                            <span x-show="leg.baggage" x-text="leg.baggage + ' checked'"></span>
                            <span x-show="leg.cabinBaggage">·</span>
                            <span x-show="leg.cabinBaggage" x-text="leg.cabinBaggage + ' cabin'"></span>
                        </div>
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="font-semibold text-brand-900" x-text="formatTime(leg.destination.time)"></span>
                            <span class="truncate text-right text-gray-600">
                                <span class="font-medium" x-text="leg.destination.code"></span>
                                <span x-text="leg.destination.airport || leg.destination.city"></span><span x-show="leg.destination.terminal" x-text="' · T' + leg.destination.terminal"></span>
                            </span>
                        </div>
                    </div>
                </div>
                <p x-show="leg.layoverAfter" x-cloak class="py-1 pl-6 text-xs font-medium text-amber-600">
                    <span x-text="formatDuration(leg.layoverAfter)"></span> layover
                </p>
            </div>
        </template>
    </div>
</template>
