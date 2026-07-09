<x-app-layout>
    <x-slot name="header">
        <h1 class="flex items-center gap-2.5 text-2xl font-bold tracking-tight text-brand-900">
            <svg class="h-7 w-7 text-brand-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
            </svg>
            Search a Flight
        </h1>
        <p class="mt-1 text-sm text-gray-500">Find and compare flights for your booking.</p>
    </x-slot>

    <div x-data="flightSearch({ airports: @js(\App\Support\Airports::all()), searchUrl: '{{ route('flights.search') }}', fareQuoteUrl: '{{ route('flights.fare-quote') }}', fareRuleUrl: '{{ route('flights.fare-rule') }}' })"
         class="grid grid-cols-1 gap-6 lg:grid-cols-12 lg:items-start">

        {{-- Main column (full width once a search has run) --}}
        <div class="space-y-8" :class="searched ? 'lg:col-span-12' : 'lg:col-span-9'">

            {{-- Full search form --}}
            <form x-ref="form" x-show="!searched || !collapsed" @submit.prevent="submit"
                  class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">

                {{-- Trip type · Passengers · Cabin --}}
                <div class="flex flex-wrap items-center gap-3">
                    <div class="inline-flex rounded-lg bg-gray-100 p-1">
                        <template x-for="opt in [{ k: 'round', l: 'Round-trip' }, { k: 'oneway', l: 'One-way' }, { k: 'multi', l: 'Multi-city' }]" :key="opt.k">
                            <button type="button" @click="setTripType(opt.k)"
                                    :class="tripType === opt.k ? 'bg-white text-brand-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                                    class="rounded-md px-3.5 py-1.5 text-sm font-medium transition"
                                    x-text="opt.l"></button>
                        </template>
                    </div>

                    {{-- Passengers --}}
                    <div class="relative" @click.outside="paxOpen = false">
                        <button type="button" @click="paxOpen = !paxOpen"
                                class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                            <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                            </svg>
                            <span x-text="paxSummary"></span>
                            <svg class="h-4 w-4 text-gray-400 transition-transform" :class="paxOpen && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>

                        <div x-show="paxOpen" x-cloak
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="absolute left-0 z-30 mt-2 w-72 rounded-xl border border-gray-200 bg-white p-2 shadow-lg">
                            <template x-for="row in [
                                    { key: 'adults', label: 'Adults', hint: '12+ years' },
                                    { key: 'children', label: 'Children', hint: '2–11 years' },
                                    { key: 'infants', label: 'Infants', hint: 'Under 2 years' }
                                ]" :key="row.key">
                                <div class="flex items-center justify-between rounded-lg px-2 py-2">
                                    <div>
                                        <p class="text-sm font-medium text-gray-800" x-text="row.label"></p>
                                        <p class="text-xs text-gray-400" x-text="row.hint"></p>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <button type="button" @click="dec(row.key)" :disabled="!canDec(row.key)"
                                                class="flex h-7 w-7 items-center justify-center rounded-full border border-gray-300 text-gray-600 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 12h-15" />
                                            </svg>
                                        </button>
                                        <span class="w-5 text-center text-sm font-semibold text-gray-900" x-text="pax[row.key]"></span>
                                        <button type="button" @click="inc(row.key)" :disabled="!canInc(row.key)"
                                                class="flex h-7 w-7 items-center justify-center rounded-full border border-gray-300 text-gray-600 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Cabin class --}}
                    <select x-model="cabin"
                            class="rounded-lg border-gray-300 py-2 pl-3.5 pr-9 text-sm font-medium text-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="any">Any Class</option>
                        <option value="economy">Economy</option>
                        <option value="premium">Premium Economy</option>
                        <option value="business">Business</option>
                        <option value="first">First Class</option>
                    </select>
                </div>

                {{-- Flight segments --}}
                <div class="mt-5 space-y-4">
                    <template x-for="(segment, index) in segments" :key="index">
                        <div>
                            <div x-show="tripType === 'multi'" x-cloak class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                Flight <span x-text="index + 1"></span>
                            </div>

                            <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                                {{-- Origin / Destination + swap --}}
                                <div class="flex items-center rounded-lg border border-gray-300 bg-white">
                                    {{-- Origin --}}
                                    <div class="relative flex-1" x-data="airportField(segment, 'origin', airports)" @click.outside="open = false">
                                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 9.75c0 7.5-7.5 12-7.5 12s-7.5-4.5-7.5-12a7.5 7.5 0 1115 0z" />
                                            </svg>
                                        </span>
                                        <input type="text" x-model="segment.origin" @focus="open = true" placeholder="Origin" autocomplete="off"
                                               class="w-full rounded-lg border-0 bg-transparent py-3 pl-9 pr-2 text-sm text-gray-900 placeholder-gray-400 focus:ring-0" />
                                        <ul x-show="open" x-cloak
                                            class="absolute left-0 top-full z-40 mt-1 max-h-64 w-64 overflow-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                                            <template x-for="a in filtered" :key="a.code">
                                                <li @click="pick(a)" class="flex cursor-pointer items-center justify-between gap-3 px-3 py-2 text-sm hover:bg-gray-50">
                                                    <span class="min-w-0 truncate">
                                                        <span class="font-medium text-brand-900" x-text="a.city"></span>
                                                        <span class="text-gray-400" x-text="a.country"></span>
                                                    </span>
                                                    <span class="font-mono text-xs font-semibold text-gray-500" x-text="a.code"></span>
                                                </li>
                                            </template>
                                            <li x-show="!filtered.length" class="px-3 py-2 text-sm text-gray-400">No matches</li>
                                        </ul>
                                    </div>

                                    <button type="button" @click="swap(index)" title="Swap origin and destination"
                                            class="mx-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 transition hover:border-blue-300 hover:text-blue-600">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-9L21 7.5m0 0L16.5 3m4.5 4.5H7.5" />
                                        </svg>
                                    </button>

                                    {{-- Destination --}}
                                    <div class="relative flex-1" x-data="airportField(segment, 'dest', airports)" @click.outside="open = false">
                                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 9.75c0 7.5-7.5 12-7.5 12s-7.5-4.5-7.5-12a7.5 7.5 0 1115 0z" />
                                            </svg>
                                        </span>
                                        <input type="text" x-model="segment.dest" @focus="open = true" placeholder="Destination" autocomplete="off"
                                               class="w-full rounded-lg border-0 bg-transparent py-3 pl-9 pr-2 text-sm text-gray-900 placeholder-gray-400 focus:ring-0" />
                                        <ul x-show="open" x-cloak
                                            class="absolute right-0 top-full z-40 mt-1 max-h-64 w-64 overflow-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                                            <template x-for="a in filtered" :key="a.code">
                                                <li @click="pick(a)" class="flex cursor-pointer items-center justify-between gap-3 px-3 py-2 text-sm hover:bg-gray-50">
                                                    <span class="min-w-0 truncate">
                                                        <span class="font-medium text-brand-900" x-text="a.city"></span>
                                                        <span class="text-gray-400" x-text="a.country"></span>
                                                    </span>
                                                    <span class="font-mono text-xs font-semibold text-gray-500" x-text="a.code"></span>
                                                </li>
                                            </template>
                                            <li x-show="!filtered.length" class="px-3 py-2 text-sm text-gray-400">No matches</li>
                                        </ul>
                                    </div>
                                </div>

                                {{-- Departure / Return + remove --}}
                                <div class="flex items-center gap-3">
                                    <div class="relative flex-1">
                                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                            </svg>
                                        </span>
                                        <input type="text" readonly x-flatpickr="{ model: 'segment.departure' }" placeholder="Departure on" autocomplete="off"
                                               class="w-full cursor-pointer rounded-lg border-gray-300 bg-white py-3 pl-9 pr-3 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:ring-blue-500" />
                                    </div>

                                    <div class="relative flex-1" x-show="tripType === 'round'" x-cloak>
                                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                            </svg>
                                        </span>
                                        <input type="text" readonly x-flatpickr="{ model: 'returnDate', min: 'segment.departure' }" placeholder="Return on" autocomplete="off"
                                               class="w-full cursor-pointer rounded-lg border-gray-300 bg-white py-3 pl-9 pr-3 text-sm text-gray-900 placeholder-gray-400 focus:border-blue-500 focus:ring-blue-500" />
                                    </div>

                                    <button type="button" x-show="tripType === 'multi' && segments.length > 2" x-cloak
                                            @click="removeSegment(index)" title="Remove flight"
                                            class="flex h-[46px] w-11 shrink-0 items-center justify-center rounded-lg border border-gray-300 text-gray-400 transition hover:border-red-300 hover:bg-red-50 hover:text-red-600">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Add flight (multi-city) --}}
                <div x-show="tripType === 'multi'" x-cloak class="mt-4">
                    <button type="button" @click="addSegment()"
                            class="inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 transition hover:text-blue-700">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Add flight
                    </button>
                </div>

                {{-- Search --}}
                <div class="mt-6 flex justify-end border-t border-gray-100 pt-5">
                    <button type="submit" :disabled="loading"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-60">
                        <svg x-show="!loading" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                        <svg x-show="loading" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <span x-text="loading ? 'Searching…' : 'Search Flights'"></span>
                    </button>
                </div>
            </form>

            {{-- Collapsed search summary bar --}}
            <div x-show="searched && collapsed" x-cloak
                 class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-brand-900" x-text="summary"></p>
                        <p class="text-xs text-gray-400">Edit to change your search</p>
                    </div>
                </div>
                <button type="button" @click="editSearch()"
                        class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                    </svg>
                    Edit search
                </button>
            </div>

            {{-- Recent searches (sample data, hidden once a search runs) --}}
            <section x-show="!searched && recent.length" x-cloak>
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-brand-900">Recent searches</h2>
                    <button type="button" @click="clearRecent()"
                            class="text-sm font-medium text-gray-500 transition hover:text-gray-700">
                        Clear
                    </button>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <template x-for="item in recent" :key="item.id">
                        <div @click="applySearch(item)"
                             class="group relative cursor-pointer rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-blue-300 hover:shadow">
                            <button type="button" @click.stop="removeRecent(item.id)" title="Remove"
                                    class="absolute right-2 top-2 flex h-6 w-6 items-center justify-center rounded-full text-gray-300 transition hover:bg-gray-100 hover:text-gray-500">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                            <div class="flex items-center gap-2 pr-6">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                                    </svg>
                                </span>
                                <p class="truncate text-sm font-semibold text-brand-900" x-text="item.routeText"></p>
                            </div>
                            <p class="mt-2 text-xs text-gray-500">
                                <span x-text="item.dateText"></span>
                                <span class="mx-1 text-gray-300">·</span>
                                <span x-text="item.metaText"></span>
                            </p>
                        </div>
                    </template>
                </div>
            </section>

            {{-- Recent bookings (sample data) --}}
            <section x-show="!searched" x-cloak>
                @php
                    $recentBookings = [
                        ['ref' => 'BK-20451', 'pax' => 'Mike Alibo', 'route' => 'MNL → CEB', 'date' => 'Jul 2, 2026', 'status' => 'Ticketed', 'badge' => 'bg-blue-50 text-blue-700 ring-blue-600/20', 'amount' => '₱ 7,450'],
                        ['ref' => 'BK-20448', 'pax' => 'Anna Cruz', 'route' => 'MNL → SIN', 'date' => 'Jul 12, 2026', 'status' => 'Confirmed', 'badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', 'amount' => '₱ 18,200'],
                        ['ref' => 'BK-20442', 'pax' => 'Jose Rizal', 'route' => 'CEB → HKG', 'date' => 'Aug 2, 2026', 'status' => 'Pending', 'badge' => 'bg-amber-50 text-amber-700 ring-amber-600/20', 'amount' => '₱ 12,300'],
                        ['ref' => 'BK-20435', 'pax' => 'Maria Santos', 'route' => 'MNL → MPH', 'date' => 'Jun 30, 2026', 'status' => 'Cancelled', 'badge' => 'bg-red-50 text-red-700 ring-red-600/20', 'amount' => '₱ 4,300'],
                    ];
                @endphp

                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                        <h2 class="text-base font-semibold text-brand-900">Recent bookings</h2>
                        <a href="#" class="text-sm font-medium text-blue-600 transition hover:text-blue-700">View all →</a>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    <th class="px-5 py-3">Reference</th>
                                    <th class="px-5 py-3">Passenger</th>
                                    <th class="px-5 py-3">Route</th>
                                    <th class="px-5 py-3">Travel date</th>
                                    <th class="px-5 py-3">Status</th>
                                    <th class="px-5 py-3 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($recentBookings as $b)
                                    <tr class="transition hover:bg-gray-50">
                                        <td class="whitespace-nowrap px-5 py-3.5 font-medium text-blue-600">{{ $b['ref'] }}</td>
                                        <td class="whitespace-nowrap px-5 py-3.5 text-gray-700">{{ $b['pax'] }}</td>
                                        <td class="whitespace-nowrap px-5 py-3.5 font-medium text-brand-900">{{ $b['route'] }}</td>
                                        <td class="whitespace-nowrap px-5 py-3.5 text-gray-600">{{ $b['date'] }}</td>
                                        <td class="px-5 py-3.5">
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $b['badge'] }}">{{ $b['status'] }}</span>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-3.5 text-right font-medium text-gray-900">{{ $b['amount'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            {{-- Results --}}
            <section x-show="searched" x-cloak>
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">

                    {{-- Filter sidebar --}}
                    <aside class="lg:col-span-1">
                        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm lg:sticky lg:top-20">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-brand-900">Filters</h3>
                                <button type="button" @click="resetFilters()" class="text-xs font-medium text-blue-600 hover:text-blue-700">Reset</button>
                            </div>

                            <div class="mt-4">
                                <label class="mb-1 block text-xs font-medium text-gray-500">Sort by</label>
                                <select x-model="sort" class="w-full rounded-lg border-gray-300 py-1.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="price">Cheapest</option>
                                    <option value="duration">Fastest</option>
                                    <option value="departure">Earliest departure</option>
                                </select>
                            </div>

                            <div class="mt-4 border-t border-gray-100 pt-4">
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Stops</p>
                                <div class="space-y-1.5">
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" value="0" x-model="filters.stops" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"> Non-stop
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" value="1" x-model="filters.stops" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"> 1 stop
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" value="2" x-model="filters.stops" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"> 2+ stops
                                    </label>
                                </div>
                            </div>

                            <div class="mt-4 border-t border-gray-100 pt-4" x-show="airlineOptions.length">
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Airlines</p>
                                <div class="max-h-40 space-y-1.5 overflow-auto pr-1">
                                    <template x-for="a in airlineOptions" :key="a.code">
                                        <label class="flex items-center gap-2 text-sm text-gray-700">
                                            <input type="checkbox" :value="a.code" x-model="filters.airlines" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                            <span class="truncate" x-text="a.name"></span>
                                        </label>
                                    </template>
                                </div>
                            </div>

                            <div class="mt-4 border-t border-gray-100 pt-4" x-show="priceBounds.max > 0">
                                <div class="mb-2 flex items-center justify-between">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Max price</p>
                                    <span class="text-xs font-semibold text-brand-900"><span x-text="currency"></span> <span x-text="money(filters.maxPrice)"></span></span>
                                </div>
                                <input type="range" :min="priceBounds.min" :max="priceBounds.max" x-model.number="filters.maxPrice" class="w-full accent-blue-600">
                            </div>
                        </div>
                    </aside>

                    {{-- Results list --}}
                    <div class="space-y-4 lg:col-span-3">

                        <div x-show="!loading" class="flex items-center justify-between text-sm text-gray-500">
                            <p><span class="font-semibold text-brand-900" x-text="visibleResults.length"></span> of <span x-text="results.length"></span> flights</p>
                            <p x-show="traceId" x-cloak class="text-xs text-gray-300">Trace <span x-text="traceId"></span></p>
                        </div>

                        {{-- Loading skeletons --}}
                        <div x-show="loading" class="space-y-4">
                            <template x-for="i in 4" :key="i">
                                <div class="h-28 animate-pulse rounded-xl border border-gray-200 bg-white"></div>
                            </template>
                        </div>

                        {{-- Error --}}
                        <div x-show="!loading && error" x-cloak class="rounded-xl border border-red-200 bg-red-50 p-8 text-center">
                            <p class="text-sm font-medium text-red-700" x-text="error"></p>
                            <button type="button" @click="submit()" class="mt-3 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Try again</button>
                        </div>

                        {{-- No provider results --}}
                        <div x-show="!loading && !error && results.length === 0" x-cloak class="rounded-xl border border-gray-200 bg-white p-10 text-center">
                            <p class="text-sm font-medium text-brand-900">No flights found</p>
                            <p class="mt-1 text-sm text-gray-500">Try different dates or airports.</p>
                        </div>

                        {{-- Filtered to empty --}}
                        <div x-show="!loading && !error && results.length > 0 && visibleResults.length === 0" x-cloak class="rounded-xl border border-gray-200 bg-white p-10 text-center">
                            <p class="text-sm font-medium text-brand-900">No flights match your filters</p>
                            <button type="button" @click="resetFilters()" class="mt-3 text-sm font-medium text-blue-600 hover:text-blue-700">Clear filters</button>
                        </div>

                        {{-- Result cards --}}
                        <template x-for="offer in visibleResults" :key="offer.resultIndex">
                            <div x-data="{ expanded: false }" class="rounded-xl border border-gray-200 bg-white shadow-sm">
                                <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center">
                                    {{-- Airline --}}
                                    <div class="flex items-center gap-3 sm:w-44">
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-xs font-bold text-brand-700" x-text="offer.airlineCode"></span>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-brand-900" x-text="offer.airlineName || offer.airlineCode"></p>
                                            <p class="truncate text-xs text-gray-400" x-text="offer.flightNumbers.join(', ')"></p>
                                        </div>
                                    </div>

                                    {{-- Itinerary --}}
                                    <div class="flex flex-1 items-center justify-between gap-3">
                                        <div class="text-center">
                                            <p class="text-lg font-semibold text-brand-900" x-text="formatTime(offer.departure.time)"></p>
                                            <p class="text-xs font-medium text-gray-500" x-text="offer.departure.code"></p>
                                        </div>
                                        <div class="flex flex-1 flex-col items-center">
                                            <span class="text-[11px] text-gray-400" x-text="formatDuration(offer.duration)"></span>
                                            <div class="my-1 flex w-full items-center gap-1">
                                                <span class="h-1.5 w-1.5 rounded-full bg-gray-300"></span>
                                                <span class="h-px flex-1 bg-gray-200"></span>
                                                <svg class="h-3.5 w-3.5 -rotate-90 text-gray-400" fill="currentColor" viewBox="0 0 24 24">
                                                    <path d="M2.5 19h19v2h-19v-2zm19.57-9.36c-.21-.8-1.04-1.28-1.84-1.06L14.92 10 8 3.57 6.09 4.08l4.15 7.19-4.76 1.28-1.89-1.48-1.45.39 1.81 3.14.76 1.31 1.45-.39 5.31-1.42 4.32-1.16L21 11.49c.81-.23 1.28-1.05 1.07-1.85z" />
                                                </svg>
                                                <span class="h-px flex-1 bg-gray-200"></span>
                                                <span class="h-1.5 w-1.5 rounded-full bg-gray-300"></span>
                                            </div>
                                            <span class="text-[11px] font-medium" :class="offer.stops === 0 ? 'text-emerald-600' : 'text-amber-600'" x-text="stopsLabel(offer.stops)"></span>
                                        </div>
                                        <div class="text-center">
                                            <p class="text-lg font-semibold text-brand-900" x-text="formatTime(offer.arrival.time)"></p>
                                            <p class="text-xs font-medium text-gray-500" x-text="offer.arrival.code"></p>
                                        </div>
                                    </div>

                                    {{-- Price + select --}}
                                    <div class="flex items-center justify-between gap-3 border-t border-gray-100 pt-3 sm:w-40 sm:flex-col sm:items-end sm:border-l sm:border-t-0 sm:pl-4 sm:pt-0">
                                        <div class="text-right">
                                            <p class="text-[11px] text-gray-400">total fare</p>
                                            <p class="text-lg font-bold text-brand-900"><span x-text="currency"></span> <span x-text="money(offer.price.offeredFare)"></span></p>
                                        </div>
                                        <button type="button" @click="selectOffer(offer)"
                                                :disabled="selecting === offer.resultIndex"
                                                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">
                                            <svg x-show="selecting === offer.resultIndex" x-cloak class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                            </svg>
                                            <span x-text="selecting === offer.resultIndex ? 'Pricing…' : 'Select'"></span>
                                        </button>
                                    </div>
                                </div>

                                {{-- Meta + details toggle --}}
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-gray-100 px-4 py-2 text-xs text-gray-500">
                                    <span class="inline-flex items-center gap-1">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 013 3h-15a3 3 0 013-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 01-.982-3.172M9.497 14.25a7.454 7.454 0 00.981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 007.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 002.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 012.916.52 6.003 6.003 0 01-5.395 4.972m0 0a6.726 6.726 0 01-2.749 1.35m0 0a6.772 6.772 0 01-3.044 0" /></svg>
                                        <span x-text="offer.cabin"></span>
                                    </span>
                                    <span x-show="offer.baggage" class="inline-flex items-center gap-1">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>
                                        <span x-text="offer.baggage"></span> checked
                                    </span>
                                    <span x-show="offer.isRefundable" x-cloak class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700">Refundable</span>
                                    <span x-show="offer.isLcc" x-cloak class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 font-medium text-gray-600">Low-cost</span>
                                    <button type="button" @click="expanded = !expanded" class="ml-auto inline-flex items-center gap-1 font-medium text-blue-600 hover:text-blue-700">
                                        <span x-text="expanded ? 'Hide details' : 'Flight details'"></span>
                                        <svg class="h-3.5 w-3.5 transition-transform" :class="expanded && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                    </button>
                                </div>

                                {{-- Expandable legs --}}
                                <div x-show="expanded" x-cloak
                                     x-transition:enter="transition ease-out duration-200"
                                     x-transition:enter-start="opacity-0"
                                     x-transition:enter-end="opacity-100"
                                     class="space-y-4 border-t border-gray-100 bg-gray-50/60 px-4 py-4">
                                    <template x-for="(trip, ti) in offer.trips" :key="ti">
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
                                                            <div class="flex items-center gap-2 text-xs text-gray-400">
                                                                <span x-text="leg.flightNumber"></span>
                                                                <span>·</span>
                                                                <span x-text="formatDuration(leg.duration)"></span>
                                                                <span>·</span>
                                                                <span x-text="leg.cabin"></span>
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
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </section>
        </div>

        {{-- Right rail: promo + popular destinations (hidden once a search runs) --}}
        <div class="space-y-6 lg:col-span-3" x-show="!searched" x-cloak>

            {{-- Deals / promo banner --}}
            <section>
                <div class="relative overflow-hidden rounded-xl bg-gradient-to-br from-brand-900 to-brand-700 p-6 text-white">
                    <div class="pointer-events-none absolute -right-8 -top-10 h-44 w-44 rounded-full bg-accent/20 blur-2xl"></div>
                    <div class="pointer-events-none absolute -bottom-12 -left-10 h-40 w-40 rounded-full bg-blue-500/20 blur-2xl"></div>

                    <div class="relative">
                        <span class="inline-flex items-center rounded-full bg-accent px-2.5 py-0.5 text-xs font-bold uppercase tracking-wide text-brand-900">
                            Seat Sale
                        </span>
                        <h3 class="mt-3 text-lg font-bold leading-snug">Earn 2% wallet credit on every flight</h3>
                        <p class="mt-1 text-sm text-white/70">Book any flight this month and get instant credit back to your e-wallet.</p>
                        <a href="#"
                           class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-brand-900 shadow-sm transition hover:bg-accent-500">
                            View deals
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                            </svg>
                        </a>
                    </div>
                </div>
            </section>

            {{-- Popular destinations --}}
            <section>
                <h2 class="mb-3 text-base font-semibold text-brand-900">Popular destinations</h2>

                <div class="space-y-2">
                    <template x-for="d in [
                            { code: 'CEB', city: 'Cebu', country: 'Philippines', price: '2,940', grad: 'from-sky-500 to-blue-700' },
                            { code: 'SIN', city: 'Singapore', country: 'Singapore', price: '8,520', grad: 'from-violet-500 to-purple-700' },
                            { code: 'HKG', city: 'Hong Kong', country: 'China', price: '6,180', grad: 'from-rose-500 to-pink-700' },
                            { code: 'DVO', city: 'Davao', country: 'Philippines', price: '3,250', grad: 'from-emerald-500 to-teal-700' },
                            { code: 'NRT', city: 'Tokyo', country: 'Japan', price: '12,400', grad: 'from-amber-500 to-orange-700' },
                            { code: 'BKK', city: 'Bangkok', country: 'Thailand', price: '7,900', grad: 'from-cyan-500 to-sky-700' }
                        ]" :key="d.code">
                        <div @click="segments[0].dest = d.city + ' (' + d.code + ')'; if (!segments[0].origin) segments[0].origin = 'Manila (MNL)'; $refs.form.scrollIntoView({ behavior: 'smooth' })"
                             class="group flex cursor-pointer items-center gap-3 rounded-xl border border-gray-200 bg-white p-3 shadow-sm transition hover:border-blue-300 hover:shadow">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br text-xs font-bold tracking-wide text-white" :class="d.grad" x-text="d.code"></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-brand-900" x-text="d.city"></p>
                                <p class="truncate text-xs text-gray-500" x-text="d.country"></p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-[10px] text-gray-400">from</p>
                                <p class="text-sm font-bold text-brand-900">₱<span x-text="d.price"></span></p>
                            </div>
                        </div>
                    </template>
                </div>
            </section>
        </div>

        {{-- Fare quote / rules modal (FareQuote → confirm price before booking) --}}
        <div x-show="quoteOpen" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" @keydown.escape.window="closeQuote()">
            <div class="absolute inset-0 bg-black/40" @click="closeQuote()"></div>
            <div class="relative z-10 max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-t-2xl bg-white p-6 shadow-xl sm:rounded-2xl"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-4"
                 x-transition:enter-end="opacity-100 translate-y-0">

                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-brand-900">Confirm fare</h2>
                        <p class="mt-0.5 text-sm text-gray-500" x-show="quoteOffer" x-cloak>
                            <span x-text="quoteOffer?.airlineName || quoteOffer?.airlineCode"></span> ·
                            <span x-text="quoteOffer?.departure?.code"></span> → <span x-text="quoteOffer?.arrival?.code"></span>
                        </p>
                    </div>
                    <button type="button" @click="closeQuote()" class="rounded-md p-1 text-gray-400 transition hover:text-gray-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                {{-- Loading --}}
                <div x-show="selecting && !quote && !quoteError" class="py-10 text-center text-sm text-gray-500">Pricing this fare…</div>

                {{-- Error --}}
                <div x-show="quoteError" x-cloak class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700" x-text="quoteError"></div>

                {{-- Quote --}}
                <div x-show="quote" x-cloak class="mt-4 space-y-4">
                    <div x-show="quote?.isPriceChanged" x-cloak class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        <strong>Price updated.</strong> The fare changed since your search — the confirmed total is below.
                    </div>

                    <div class="flex items-end justify-between rounded-lg bg-gray-50 px-4 py-3">
                        <span class="text-sm text-gray-500">Confirmed total</span>
                        <span class="text-xl font-bold text-brand-900"><span x-text="currency"></span> <span x-text="money(quote?.price?.offeredFare)"></span></span>
                    </div>

                    <div class="flex flex-wrap gap-2 text-xs">
                        <span x-show="quote?.isLcc" x-cloak class="rounded-full bg-gray-100 px-2 py-0.5 font-medium text-gray-600">Low-cost</span>
                        <span x-show="quote?.isRefundable" x-cloak class="rounded-full bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700">Refundable</span>
                        <span x-show="quote && !quote.isRefundable" x-cloak class="rounded-full bg-gray-100 px-2 py-0.5 font-medium text-gray-500">Non-refundable</span>
                        <span x-show="quote?.isPassportMandatory" x-cloak class="rounded-full bg-blue-50 px-2 py-0.5 font-medium text-blue-700">Passport required</span>
                    </div>

                    <div x-show="quote?.fareBreakdown?.length" x-cloak>
                        <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">Fare breakdown</p>
                        <div class="divide-y divide-gray-100 rounded-lg border border-gray-100 text-sm">
                            <template x-for="(b, i) in quote.fareBreakdown" :key="i">
                                <div class="flex items-center justify-between px-3 py-2">
                                    <span class="text-gray-600"><span x-text="b.count"></span> × <span x-text="b.passengerType"></span></span>
                                    <span class="text-brand-900"><span x-text="currency"></span> <span x-text="money((b.baseFare + b.tax) * b.count)"></span></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div>
                        <button type="button" @click="loadRules()" x-show="rules === null && !rulesLoading" class="text-sm font-medium text-blue-600 hover:text-blue-700">View fare rules</button>
                        <p x-show="rulesLoading" x-cloak class="text-sm text-gray-500">Loading fare rules…</p>
                        <p x-show="rulesError" x-cloak class="text-sm text-red-600" x-text="rulesError"></p>
                        <div x-show="rules && rules.length" x-cloak class="space-y-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Fare rules</p>
                            <template x-for="(r, i) in rules" :key="i">
                                <div class="rounded-lg bg-gray-50 p-3 text-xs text-gray-600">
                                    <p class="font-semibold text-gray-700"><span x-text="r.origin"></span> → <span x-text="r.destination"></span> <span x-show="r.airline" x-text="'· ' + r.airline"></span></p>
                                    <p class="mt-1 whitespace-pre-line" x-text="r.detail"></p>
                                </div>
                            </template>
                        </div>
                        <p x-show="rules && rules.length === 0" x-cloak class="text-sm text-gray-500">No fare rules provided.</p>
                    </div>

                    <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-4">
                        <button type="button" @click="closeQuote()" class="text-sm font-medium text-gray-600 hover:text-gray-800">Close</button>
                        <button type="button" disabled title="Booking coming soon" class="cursor-not-allowed rounded-lg bg-gray-300 px-4 py-2 text-sm font-semibold text-white">
                            Continue to booking
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
