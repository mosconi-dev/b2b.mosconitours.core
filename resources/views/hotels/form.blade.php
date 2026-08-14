{{-- The hotel search form. Occupancy is per room, because that is how TBO prices. --}}
<div x-show="!collapsed" x-cloak class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
    <form x-ref="form" @submit.prevent="search()" class="space-y-4">

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">

            {{-- Destination --}}
            <div class="relative lg:col-span-5" @click.outside="suggestions = []">
                <x-input-label for="hotel-location" value="Destination or property" />
                <input id="hotel-location" type="text" autocomplete="off"
                       x-model="locationLabel"
                       @input.debounce.250ms="suggest()"
                       @focus="suggest()"
                       placeholder="City or hotel name"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm">

                <ul x-show="suggestions.length" x-cloak
                    class="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                    <template x-for="option in suggestions" :key="option.type + option.code">
                        <li>
                            <button type="button" @click="choose(option)"
                                    class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-gray-50">
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-brand-900" x-text="option.label"></span>
                                    <span class="block text-xs text-gray-400" x-text="option.sublabel"></span>
                                </span>
                                <span class="shrink-0 text-xs text-gray-400"
                                      x-text="option.type === 'city' ? option.hotels + ' hotels' : 'Hotel'"></span>
                            </button>
                        </li>
                    </template>
                </ul>
            </div>

            {{-- Dates. Same calendar as the flight form: a native date input renders
                 and validates differently in every browser, and reads the agent's
                 locale rather than ours. --}}
            <div class="lg:col-span-2">
                <x-input-label for="hotel-checkin" value="Check-in" />
                <div class="relative mt-1">
                    <span class="pointer-events-none absolute inset-y-0 left-3 z-10 flex items-center text-gray-400">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                        </svg>
                    </span>
                    <input id="hotel-checkin" type="text" readonly autocomplete="off"
                           x-flatpickr="{ model: 'checkIn' }" placeholder="Pick check-in"
                           class="block w-full cursor-pointer rounded-md border-gray-300 pl-9 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
            </div>
            <div class="lg:col-span-2">
                <div class="flex items-baseline justify-between gap-2">
                    <x-input-label for="hotel-checkout" value="Check-out" />
                    <span x-show="nights" x-cloak class="text-xs text-gray-400"
                          x-text="nights + (nights === 1 ? ' night' : ' nights')"></span>
                </div>
                <div class="relative mt-1">
                    <span class="pointer-events-none absolute inset-y-0 left-3 z-10 flex items-center text-gray-400">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                        </svg>
                    </span>
                    <input id="hotel-checkout" type="text" readonly autocomplete="off"
                           x-flatpickr="{ model: 'checkOut', min: 'checkOutMin', max: 'checkOutMax' }" placeholder="Pick check-out"
                           class="block w-full cursor-pointer rounded-md border-gray-300 pl-9 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
            </div>

            {{-- Nationality. Never defaulted silently: it changes the price, and TBO
                 is explicit that getting it wrong is our problem, not theirs — so the
                 field has to show the code the search actually sends.

                 The options are rendered here rather than by an x-for template: with
                 the template, x-model runs before the options exist, the select falls
                 back to its first entry, and the agent reads "Afghanistan" off a search
                 priced for somewhere else. --}}
            <div class="lg:col-span-3">
                <x-input-label for="hotel-nationality" value="Lead guest nationality" />
                <select id="hotel-nationality" x-model="guestNationality"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm">
                    @foreach ($countries as $country)
                        <option value="{{ $country->code }}">{{ $country->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Rooms --}}
        <div class="rounded-lg border border-gray-200 bg-gray-50/60 p-4">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-brand-900">Rooms &amp; guests</p>
                <button type="button" @click="addRoom()" x-show="rooms.length < 6"
                        class="text-sm font-medium text-brand-700 hover:text-brand-900">+ Add room</button>
            </div>

            {{-- gap, not space-y: the x-for template is a hidden sibling. --}}
            <div class="mt-3 flex flex-col gap-3">
                <template x-for="(room, i) in rooms" :key="i">
                    <div class="flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-3">
                        <span class="w-16 text-xs font-semibold uppercase tracking-wide text-gray-500"
                              x-text="'Room ' + (i + 1)"></span>

                        {{-- Options are written out here rather than built with x-for.
                             x-model is applied before x-for has created any options, so
                             the select has nothing to select and falls back to showing
                             its first one — which is how this form came to display "1
                             adult" while searching, pricing and booking for two. The
                             nationality select was fixed the same way. --}}
                        <div>
                            <label class="block text-xs text-gray-500">Adults</label>
                            <select x-model.number="room.adults"
                                    class="mt-0.5 rounded-md border-gray-300 py-1 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                @for ($n = 1; $n <= 8; $n++)
                                    <option value="{{ $n }}">{{ $n }}</option>
                                @endfor
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs text-gray-500">Children</label>
                            <select x-model.number="room.children" @change="syncAges(room)"
                                    class="mt-0.5 rounded-md border-gray-300 py-1 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                @for ($n = 0; $n <= 4; $n++)
                                    <option value="{{ $n }}">{{ $n }}</option>
                                @endfor
                            </select>
                        </div>

                        {{-- One age per child, in the room that child sleeps in — TBO
                             refuses the request otherwise. --}}
                        <template x-for="(age, a) in room.childrenAges" :key="a">
                            <div>
                                <label class="block text-xs text-gray-500" x-text="'Child ' + (a + 1) + ' age'"></label>
                                <select x-model.number="room.childrenAges[a]"
                                        class="mt-0.5 rounded-md border-gray-300 py-1 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                    @for ($n = 0; $n <= 18; $n++)
                                        <option value="{{ $n }}">{{ $n }}</option>
                                    @endfor
                                </select>
                            </div>
                        </template>

                        <button type="button" @click="removeRoom(i)" x-show="rooms.length > 1"
                                class="ml-auto text-sm text-gray-400 hover:text-red-600">Remove</button>
                    </div>
                </template>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <label class="inline-flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" x-model="refundableOnly"
                       class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                Refundable rates only
            </label>

            <div class="flex items-center gap-3">
                <p x-show="error" x-cloak class="text-sm text-red-600" x-text="error"></p>
                <x-primary-button type="submit" ::disabled="loading">
                    <span x-show="!loading">Search hotels</span>
                    <span x-show="loading" x-cloak>Searching…</span>
                </x-primary-button>
            </div>
        </div>
    </form>
</div>
