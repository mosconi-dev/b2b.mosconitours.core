<x-app-layout>
    <x-slot name="header">
        <x-page-heading title="Search a Hotel" subtitle="Find and compare rooms for your booking.">
            <x-slot name="icon">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" />
                </svg>
            </x-slot>
        </x-page-heading>
    </x-slot>

    <div x-data="hotelSearch({
            suggestUrl: '{{ route('hotels.suggest') }}',
            searchUrl: '{{ route('hotels.search') }}',
            roomsUrl: '{{ route('hotels.rooms', ['code' => '__CODE__']) }}',
         })"
         {{-- gap, not space-y: the search form above is display:none once collapsed but
              still counts as a sibling, so space-y put 20px of nothing above the summary
              bar. Gaps ignore children that generate no box. --}}
         class="flex flex-col gap-5">

        @include('hotels.form')

        {{-- Collapsed summary once a search has run --}}
        <div x-show="collapsed" x-cloak
             class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-brand-900" x-text="summary"></p>
                <p class="text-xs text-gray-400">Modify to change your search</p>
            </div>
            <button type="button" @click="collapsed = false"
                    class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                Modify
            </button>
        </div>

        {{-- A search that only half-succeeded says so. A page showing nine tenths of
             a city is indistinguishable from one showing all of it. --}}
        <div x-show="result && result.partial" x-cloak
             class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
            <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-2.994-1.5-3.86 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
            </svg>
            <p class="text-sm text-amber-800">
                <span class="font-medium">This is not the whole city.</span>
                {{-- x-show renders its children straight away, unlike the x-if below, so
                     this evaluates once against a null result before the first search. --}}
                About <span x-text="result?.hotelsMissed"></span> properties could not be checked just now.
                <button type="button" @click="search(true)" class="font-medium underline">Search again</button>
                to include them.
            </p>
        </div>

        {{-- Results --}}
        <template x-if="result && !loading">
            <div>
                {{-- Booking progress — Select Hotel is the current step here, and it is
                     only ever this one: the room is chosen on the property's own page. --}}
                <div class="mb-6 rounded-xl border border-gray-200 bg-white px-4 py-5 shadow-sm">
                    <x-hotel-stepper :current="1" />
                </div>

                <div class="grid grid-cols-1 gap-5 lg:grid-cols-12 lg:items-start">

                {{-- Filters. Sticky under the 4rem top bar, and below it in the stack —
                     the grid's lg:items-start is what stops the column stretching to the
                     results' full height, which would leave nothing to scroll against. --}}
                <aside class="space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm lg:sticky lg:top-20 lg:z-10 lg:col-span-3 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto">
                    <div>
                        <p class="text-sm font-semibold text-brand-900">Sort</p>
                        <select x-model="sort" class="mt-2 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            <option value="price">Price, lowest first</option>
                            <option value="price_desc">Price, highest first</option>
                            <option value="rating">Star rating</option>
                            <option value="name">Name</option>
                        </select>
                    </div>

                    <div class="space-y-2 border-t border-gray-100 pt-4">
                        <p class="text-sm font-semibold text-brand-900">Filter</p>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" x-model="onlyRefundable" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                            Refundable
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" x-model="onlyBreakfast" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                            Breakfast included
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" x-model="onlyTransfers" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                            Transfers included
                        </label>
                    </div>

                    <div class="border-t border-gray-100 pt-4">
                        <label class="block text-sm font-semibold text-brand-900">Minimum stars</label>
                        {{-- Static options: x-model runs before x-for creates any, so a
                             filter restored from the URL would show "Any" while
                             filtering on three stars. See hotels/form.blade.php. --}}
                        <select x-model.number="minRating" class="mt-2 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            <option value="0">Any</option>
                            @for ($n = 1; $n <= 5; $n++)
                                <option value="{{ $n }}">{{ $n }}+</option>
                            @endfor
                        </select>
                    </div>

                    <p class="border-t border-gray-100 pt-4 text-xs text-gray-400">
                        <span x-text="filtered.length"></span> of <span x-text="result.offers.length"></span> properties ·
                        <span x-text="result.hotelsSearched"></span> checked
                    </p>
                </aside>

                {{-- Cards --}}
                {{-- gap, not space-y: x-if and x-for leave their <template> tags in the
                     DOM, so space-y's sibling rule gives the first card a top margin and
                     drops the whole column 16px below the filters beside it. --}}
                <div class="flex flex-col gap-4 lg:col-span-9">
                    <template x-if="!filtered.length">
                        <div class="rounded-xl border border-gray-200 bg-white p-12 text-center shadow-sm">
                            <p class="text-sm font-medium text-brand-900">No rooms match</p>
                            <p class="mt-1 text-sm text-gray-500"
                               x-text="result.offers.length ? 'Try relaxing the filters.' : 'No availability for these dates and occupancy.'"></p>
                        </div>
                    </template>

                    <template x-for="offer in filtered" :key="offer.hotelCode">
                        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition hover:border-gray-300 hover:shadow">
                            <div class="flex flex-col sm:flex-row">

                                {{-- The photograph, at a size worth having. It is how an
                                     agent recognises a property, and it was the first thing
                                     to go when this card chased the flight layout. --}}
                                <div class="relative h-44 w-full shrink-0 bg-gray-100 sm:h-auto sm:w-56">
                                    <template x-if="offer.thumbnail">
                                        <img :src="offer.thumbnail" :alt="offer.name"
                                             class="absolute inset-0 h-full w-full object-cover" loading="lazy">
                                    </template>
                                    <template x-if="!offer.thumbnail">
                                        <div class="absolute inset-0 flex items-center justify-center text-gray-300">
                                            <svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke-width="1.2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21" />
                                            </svg>
                                        </div>
                                    </template>
                                </div>

                                {{-- What the property is --}}
                                <div class="min-w-0 flex-1 p-4">
                                    <p class="truncate text-base font-semibold text-brand-900" x-text="offer.name"></p>

                                    <p x-show="offer.rating" x-cloak class="mt-0.5 text-xs text-amber-500">
                                        <template x-for="n in (offer.rating || 0)" :key="n"><span>★</span></template>
                                    </p>

                                    <p x-show="offer.address" x-cloak class="mt-1 flex items-start gap-1 text-xs text-gray-500">
                                        <svg class="mt-px h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                        </svg>
                                        <span class="line-clamp-2" x-text="offer.address"></span>
                                    </p>

                                    {{-- What an agent screens on, in one line --}}
                                    <div class="mt-3 flex flex-wrap items-center gap-1.5">
                                        <span x-show="offer.hasRefundable" x-cloak class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Refundable</span>
                                        <span x-show="!offer.hasRefundable" x-cloak class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">Non-refundable</span>
                                        <span x-show="offer.hasBreakfast" x-cloak class="rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">Breakfast</span>
                                        <span x-show="offer.hasTransfers" x-cloak class="rounded-full bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-700">Transfers</span>
                                    </div>

                                    {{-- The one fact that decides a booking more often than any
                                         other, so it gets a line of its own rather than a chip. --}}
                                    <p x-show="freeCancellation(offer)" x-cloak class="mt-2 text-xs font-medium text-emerald-700"
                                       x-text="'Free cancellation before ' + formatDay(freeCancellation(offer))"></p>

                                    <p class="mt-2 text-xs text-gray-400"
                                       x-text="offer.roomCount + (offer.roomCount === 1 ? ' room type available' : ' room types available')"></p>
                                </div>

                                {{-- Price and the way forward --}}
                                <div class="flex items-end justify-between gap-3 border-t border-gray-100 p-4 sm:w-52 sm:flex-col sm:items-end sm:justify-center sm:border-l sm:border-t-0">
                                    <div class="text-right">
                                        <p class="text-xl font-bold text-brand-900" x-text="money(offer.lowestFare, offer.currency)"></p>
                                        {{-- Said plainly because Agoda and its kind quote per
                                             night: this is the whole stay, taxes in. --}}
                                        <p class="mt-0.5 text-xs text-gray-500"
                                           x-text="'total · ' + result.nights + (result.nights === 1 ? ' night' : ' nights') + ' · ' + result.rooms + (result.rooms === 1 ? ' room' : ' rooms')"></p>
                                        <p class="text-[11px] text-gray-400">taxes included</p>
                                    </div>

                                    <button type="button" @click="selectHotel(offer)"
                                            :disabled="selecting === offer.hotelCode"
                                            class="mt-3 inline-flex shrink-0 items-center gap-2 rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">
                                        <svg x-show="selecting === offer.hotelCode" x-cloak class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                        </svg>
                                        <span x-text="selecting === offer.hotelCode ? 'Pricing…' : 'Select'"></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                    </div>
                </div>
            </div>
        </template>

        <div x-show="loading" x-cloak class="rounded-xl border border-gray-200 bg-white p-12 text-center shadow-sm">
            <p class="text-sm font-medium text-brand-900">Checking availability…</p>
            <p class="mt-1 text-sm text-gray-500">A city is many requests to the supplier; this takes a few seconds.</p>
        </div>
    </div>
</x-app-layout>
