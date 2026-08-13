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
         })" class="space-y-5">

        {{-- This page is step 1 and only step 1. Choosing a room happens on the
             property's own page, so the stepper here is static. --}}
        <div x-show="result" x-cloak class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <x-hotel-stepper :current="1" />
        </div>

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
                        <select x-model.number="minRating" class="mt-2 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            <option :value="0">Any</option>
                            <template x-for="n in 5" :key="n"><option :value="n" x-text="n + '+'"></option></template>
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
                        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                            <div class="flex flex-col gap-4 p-5 sm:flex-row">
                                <div class="h-28 w-full shrink-0 overflow-hidden rounded-lg bg-gray-100 sm:w-40">
                                    <template x-if="offer.thumbnail">
                                        <img :src="offer.thumbnail" :alt="offer.name" class="h-full w-full object-cover" loading="lazy">
                                    </template>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="min-w-0">
                                            <p class="truncate text-base font-semibold text-brand-900" x-text="offer.name"></p>
                                            <p class="mt-0.5 flex items-center gap-1.5 text-xs text-amber-500">
                                                <template x-for="n in (offer.rating || 0)" :key="n"><span>★</span></template>
                                                <span class="truncate text-gray-400" x-text="offer.address"></span>
                                            </p>
                                        </div>
                                        <div class="shrink-0 text-right">
                                            <p class="text-lg font-semibold text-brand-900"
                                               x-text="money(offer.lowestFare, offer.currency)"></p>
                                            <p class="text-xs text-gray-400"
                                               x-text="result.nights + (result.nights === 1 ? ' night' : ' nights') + ' · ' + result.rooms + (result.rooms === 1 ? ' room' : ' rooms')"></p>
                                        </div>
                                    </div>

                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        <template x-if="offer.hasRefundable">
                                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Refundable</span>
                                        </template>
                                        <template x-if="offer.hasBreakfast">
                                            <span class="rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700 ring-1 ring-inset ring-sky-600/20">Breakfast</span>
                                        </template>
                                        <template x-if="offer.hasTransfers">
                                            <span class="rounded-full bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-700 ring-1 ring-inset ring-violet-600/20">Transfers</span>
                                        </template>
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600"
                                              x-text="offer.roomCount + (offer.roomCount === 1 ? ' rate' : ' rates')"></span>
                                    </div>

                                    {{-- Step 1 → step 2. The rooms get their own page: choosing
                                         one means reading policies and prices, and a decision
                                         that size deserves the screen. It also makes the step
                                         addressable, so back and forward both work. --}}
                                    <a :href="roomsUrl(offer)"
                                       class="mt-3 inline-block text-sm font-medium text-brand-700 hover:text-brand-900">
                                        View rooms &rarr;
                                    </a>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        <div x-show="loading" x-cloak class="rounded-xl border border-gray-200 bg-white p-12 text-center shadow-sm">
            <p class="text-sm font-medium text-brand-900">Checking availability…</p>
            <p class="mt-1 text-sm text-gray-500">A city is many requests to the supplier; this takes a few seconds.</p>
        </div>
    </div>
</x-app-layout>
