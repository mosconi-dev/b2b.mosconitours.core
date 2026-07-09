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
