<x-app-layout>
    <x-slot name="header">
        <x-page-heading title="Book your flight" subtitle="Complete the steps below to confirm your booking." />
    </x-slot>

    <div x-data="bookingWizard(@js([
            'traceId' => $traceId,
            'resultIndex' => $resultIndex,
            'quote' => $quote,
            'ssr' => $ssr,
            'summary' => $summary,
            'oldFare' => $oldFare,
            // Search-only seat availability, carried through so the booking can hold
            // what Book needs and FareQuote no longer returns.
            'seats' => $seats,
            'resultType' => $resultType,
            'bookingUrl' => route('bookings.store'),
            // Two different intents: "Book another" starts a fresh search, while
            // "Change flight" / "Decline" go back to Select Flight with the search
            // that produced this fare, so the results list is restored.
            'flightsUrl' => route('flights'),
            'changeFlightUrl' => $q ? route('flights', ['q' => $q]) : route('flights'),
            'wallet' => $wallet,
            'walletRequestUrl' => $walletRequestUrl,
         ]))">

        {{-- Search context — carried from the flights search. Editable in place:
             "Modify" expands the real search form right here; submitting it
             hands off to the Select Flight page with the new search. Shown only on
             the Guest Details step. --}}
        @if ($search)
            <div x-show="step === 2" x-cloak class="mb-6">
                @if ($q)
                    <div x-data="flightSearch({
                            airports: @js(\App\Support\Airports::all()),
                            searchUrl: '{{ route('flights.search') }}',
                            redirectUrl: '{{ route('flights') }}',
                            initialQ: @js($q),
                            embedded: true,
                         })">
                        {{-- Collapsed summary (default). x-text swaps in the live summary
                             once Alpine boots; the server-rendered $search is the fallback. --}}
                        <div x-show="collapsed" x-cloak
                             class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-brand-900" x-text="summary">{{ $search }}</p>
                                    <p class="text-xs text-gray-400">Modify to change your search</p>
                                </div>
                            </div>
                            <button type="button" @click="modifySearch()"
                                    class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                </svg>
                                Modify
                            </button>
                        </div>

                        {{-- The real search form (shared with the flights page). Expands
                             when the user hits Modify; "Search Flights" navigates to
                             Select Flight with the new search. --}}
                        @include('flights.form')
                    </div>
                @else
                    {{-- No search token to pre-fill an editable form — show the carried
                         search read-only. --}}
                    <div class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                                </svg>
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-brand-900">{{ $search }}</p>
                                <p class="text-xs text-gray-400">Your current search</p>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        {{-- Progress stepper (reactive to the wizard's `step`) --}}
        <div class="mb-6 rounded-xl border border-gray-200 bg-white px-4 py-5 shadow-sm">
            @include('bookings._stepper')
        </div>

        {{-- Selected flight summary --}}
        <div class="mb-6 rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between p-4">
                <div>
                    <p class="text-sm font-semibold text-brand-900">
                        <span x-text="summary.airline || 'Selected flight'"></span>
                        <span x-show="summary.from && summary.to" class="text-gray-400"> · <span x-text="summary.from"></span> → <span x-text="summary.to"></span></span>
                    </p>
                    <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-xs text-gray-500">
                        <span x-text="quote.isLcc ? 'Low-cost' : 'GDS'"></span> ·
                        <span x-text="quote.isRefundable ? 'Refundable' : 'Non-refundable'"></span>
                        <span x-show="quote.baggage">· <span x-text="quote.baggage"></span> checked</span>
                        <span x-show="quote.cabinBaggage">· <span x-text="quote.cabinBaggage"></span> cabin</span>
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-lg font-bold text-brand-900"><span x-text="currency"></span> <span x-text="money(grandTotal)"></span></p>
                    <a :href="changeFlightUrl" x-show="step < 5" class="text-xs font-medium text-blue-600 hover:text-blue-700">Change flight</a>
                </div>
            </div>

            {{-- Full itinerary + fare conditions, so the client can re-check the flight
                 without going back to the results page. --}}
            <div x-show="quote.trips && quote.trips.length" x-cloak class="border-t border-gray-100 px-4 py-2">
                <button type="button" @click="detailsOpen = ! detailsOpen"
                        class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-700">
                    <span x-text="detailsOpen ? 'Hide flight details' : 'Flight details'"></span>
                    <svg class="h-3.5 w-3.5 transition-transform" :class="detailsOpen && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                </button>
            </div>

            <div x-show="detailsOpen" x-cloak
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 class="space-y-4 border-t border-gray-100 bg-gray-50/60 px-4 py-4">

                @include('flights._itinerary', ['trips' => 'quote.trips'])

                {{-- Cancellation / reissue fees, already priced by FareQuote --}}
                <div x-show="quote.miniFareRules && quote.miniFareRules.length" class="border-t border-gray-200 pt-3">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Fare conditions</p>
                    <div class="space-y-1.5">
                        <template x-for="(rule, ri) in quote.miniFareRules" :key="ri">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 rounded-lg bg-white p-2.5 text-xs ring-1 ring-gray-100">
                                <span class="font-medium text-brand-900">
                                    <span x-text="rule.type"></span>
                                    <span x-show="rule.journeyPoints" class="font-normal text-gray-400" x-text="' · ' + rule.journeyPoints"></span>
                                </span>
                                <span class="text-gray-600" x-text="rule.details || '—'"></span>
                            </div>
                        </template>
                    </div>
                    <p class="mt-2 text-[11px] leading-relaxed text-gray-400">
                        Fees are the airline's own and may change at ticketing.
                    </p>
                </div>
            </div>
        </div>

        {{-- ============ Step 2 · Guest Details ============ --}}
        {{-- Left rail lists the sections (Contact + each guest); the form card on the
             right shows only the active one, keeping fields at a readable width. --}}
        <section x-show="step === 2" x-cloak class="grid grid-cols-1 gap-6 lg:grid-cols-4">

            {{-- Section rail --}}
            <aside class="lg:col-span-1">
                <div class="rounded-xl border border-gray-200 bg-white p-2 shadow-sm lg:sticky lg:top-20">
                    <p class="px-3 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Sections</p>

                    <button type="button" @click="guestTab = 'contact'"
                            class="flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium transition"
                            :class="guestTab === 'contact' ? 'bg-blue-50 text-blue-700' : 'text-gray-600 hover:bg-gray-50'">
                        <span>Contact details</span>
                        <svg x-show="contactComplete" x-cloak class="h-4 w-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    </button>

                    <template x-for="(p, i) in passengers" :key="i">
                        <button type="button" @click="guestTab = i"
                                class="mt-1 flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium transition"
                                :class="guestTab === i ? 'bg-blue-50 text-blue-700' : 'text-gray-600 hover:bg-gray-50'">
                            <span class="truncate">Guest <span x-text="i + 1"></span> <span class="text-gray-400" x-text="'· ' + p.type"></span></span>
                            <svg x-show="passengerComplete(p)" x-cloak class="h-4 w-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        </button>
                    </template>
                </div>
            </aside>

            {{-- Active section form --}}
            <div class="lg:col-span-3">
                <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">

                    {{-- Contact details --}}
                    <div x-show="guestTab === 'contact'" class="space-y-4">
                        <div>
                            <h2 class="text-base font-semibold text-brand-900">Contact information</h2>
                            <p class="mt-0.5 text-sm text-gray-500">We'll send the booking confirmation here.</p>
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600">Email address</label>
                                <input type="email" x-model="contact.email" placeholder="e.g. agent@email.com" class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600">Contact number</label>
                                <div class="flex">
                                    <span class="inline-flex items-center rounded-l-lg border border-r-0 border-gray-300 bg-gray-50 pl-3 text-sm text-gray-500">+</span>
                                    <input type="text" x-model="contact.mobileCountryCode" maxlength="4" placeholder="63" class="w-14 border-y border-gray-300 bg-gray-50 px-1 text-sm text-gray-700 focus:border-blue-500 focus:ring-0" />
                                    <input type="tel" x-model="contact.phone" placeholder="e.g. 917 123 4567" class="w-full rounded-r-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500" />
                                </div>
                            </div>
                        </div>

                        {{-- Billing address. TBO requires an address on every passenger;
                             it is collected once here and copied onto each of them. --}}
                        <div class="border-t border-gray-100 pt-4">
                            <h3 class="text-sm font-semibold text-brand-900">Billing address</h3>
                            <p class="mt-0.5 text-xs text-gray-500">Required by the airline for every guest on the booking.</p>
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Address line 1</label>
                                <input type="text" x-model="contact.addressLine1" placeholder="e.g. 123 Rizal Street" class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="mb-1 block text-xs font-medium text-gray-600">Address line 2 <span class="font-normal text-gray-400">· optional</span></label>
                                <input type="text" x-model="contact.addressLine2" placeholder="e.g. Barangay San Antonio" class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600">City</label>
                                <input type="text" x-model="contact.city" placeholder="e.g. Makati" class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600">Country</label>
                                <input type="text" x-model="contact.countryCode" maxlength="2" placeholder="e.g. PH" class="w-full rounded-lg border-gray-300 text-sm uppercase placeholder:normal-case focus:border-blue-500 focus:ring-blue-500" />
                            </div>
                        </div>
                    </div>

                    {{-- Per-guest details --}}
                    <template x-for="(p, i) in passengers" :key="i">
                        <div x-show="guestTab === i" class="space-y-5">
                            <div>
                                <h2 class="text-base font-semibold text-brand-900">Guest <span x-text="i + 1"></span> <span class="font-normal text-gray-400">· <span x-text="p.type"></span></span></h2>
                                <p x-show="documentRequired" x-cloak class="mt-0.5 text-xs text-blue-700"
                                   x-text="quote.isDomestic
                                       ? 'A government ID is required for this fare — any valid ID will do.'
                                       : 'Passport details are required for this international flight.'"></p>
                            </div>

                            {{-- Exactly one lead guest, and only an adult may hold it. --}}
                            <label x-show="p.type === 'Adult'" x-cloak class="flex items-start gap-2 rounded-lg bg-gray-50 px-3 py-2">
                                <input type="radio" name="leadPax" class="mt-0.5 border-gray-300 text-blue-600 focus:ring-blue-500"
                                       :checked="p.isLeadPax" @change="setLeadPax(i)" />
                                <span class="text-xs text-gray-600">
                                    <span class="font-medium text-gray-800">Lead guest</span> — the airline's point of contact for this booking.
                                </span>
                            </label>

                            {{-- ---- Who they are ------------------------------------
                                 Everything that describes the person, including
                                 nationality: it is a fact about the guest, not about
                                 the document they happen to be carrying. --}}
                            <section class="space-y-4">
                                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">Guest information</h3>

                                <div class="grid grid-cols-2 gap-4 sm:grid-cols-12">
                                    <div class="col-span-2 sm:col-span-2">
                                        <label class="mb-1 block text-xs font-medium text-gray-600">Title</label>
                                        <select x-model="p.title" class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500">
                                            {{-- TBO accepts exactly these three (Mr=0, Miss=1, Mrs=2). --}}
                                            <option>Mr</option><option>Mrs</option><option>Miss</option>
                                        </select>
                                    </div>
                                    <div class="col-span-1 sm:col-span-5">
                                        <label class="mb-1 block text-xs font-medium text-gray-600">First name</label>
                                        <input type="text" x-model="p.firstName" placeholder="e.g. Juan" class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500" />
                                    </div>
                                    <div class="col-span-1 sm:col-span-5">
                                        <label class="mb-1 block text-xs font-medium text-gray-600">Last name</label>
                                        <input type="text" x-model="p.lastName" placeholder="e.g. Dela Cruz" class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500" />
                                    </div>
                                </div>

                                {{-- Date of birth takes three controls of its own, so it
                                     gets the wider half and the two short fields share
                                     the other. --}}
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-12">
                                    <div class="sm:col-span-3">
                                        <label class="mb-1 block text-xs font-medium text-gray-600">Gender</label>
                                        <select x-model="p.gender" class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                                                :class="p.gender ? 'text-gray-900' : 'text-gray-400'">
                                            <option value="" class="text-gray-400">Select</option><option value="M" class="text-gray-900">Male</option><option value="F" class="text-gray-900">Female</option>
                                        </select>
                                    </div>
                                    <div class="sm:col-span-3">
                                        <label class="mb-1 block text-xs font-medium text-gray-600">Nationality</label>
                                        <input type="text" x-model="p.nationality" maxlength="2" placeholder="e.g. PH" class="w-full rounded-lg border-gray-300 text-sm uppercase placeholder:normal-case focus:border-blue-500 focus:ring-blue-500" />
                                    </div>
                                    {{-- Required for every passenger: TBO rejects a blank one at
                                         Ticket, after the booking has been paid for. --}}
                                    <div class="sm:col-span-6">
                                        @include('bookings._date-select', [
                                            'field' => 'dateOfBirth',
                                            'label' => 'Date of birth',
                                            'required' => true,
                                        ])
                                    </div>
                                </div>
                            </section>

                            {{-- ---- What they travel on -----------------------------
                                 A dashed rule, not a solid one: this is a change of
                                 subject within one guest, not the end of the guest. --}}
                            <section x-show="documentRequired" x-cloak class="space-y-4 border-t border-dashed border-gray-300 pt-5">
                                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-gray-400"
                                    x-text="quote.isDomestic ? 'Government ID' : 'Passport'"></h3>

                                {{-- Which document depends on the route, not on TBO's
                                     flags: a passport abroad, any government ID at home. --}}
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-600" x-text="quote.isDomestic ? 'ID number' : 'Passport no.'"></label>
                                        <input type="text" x-model="p.documentNumber" :placeholder="quote.isDomestic ? 'e.g. UMID / driver&apos;s licence' : 'e.g. P1234567A'"
                                               class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500" />
                                    </div>
                                    @include('bookings._date-select', [
                                        'field' => 'documentExpiry',
                                        'labelExpr' => "quote.isDomestic ? 'ID expiry' : 'Passport expiry'",
                                    ])
                                </div>

                                {{-- Only a passport carries these; a domestic ID does not. --}}
                                <div x-show="! quote.isDomestic" x-cloak class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-gray-600">Issuing country</label>
                                        <input type="text" x-model="p.documentIssueCountry" maxlength="2" placeholder="e.g. PH" class="w-full rounded-lg border-gray-300 text-sm uppercase placeholder:normal-case focus:border-blue-500 focus:ring-blue-500" />
                                    </div>
                                    @include('bookings._date-select', [
                                        'field' => 'documentIssueDate',
                                        'label' => 'Issue date',
                                    ])
                                </div>
                            </section>
                        </div>
                    </template>

                    {{-- Footer --}}
                    <div class="mt-6 flex items-center justify-between border-t border-gray-100 pt-4">
                        <button type="button" x-show="guestActiveIndex > 0" x-cloak @click="guestRetreat()" class="text-sm font-medium text-gray-600 hover:text-gray-800">&larr; Back</button>
                        <a :href="changeFlightUrl" x-show="guestActiveIndex === 0" x-cloak class="text-sm font-medium text-gray-500 hover:text-gray-700">Change flight</a>
                        <button type="button" @click="guestAdvance()"
                                :disabled="! currentSectionComplete || (guestIsLast && ! canProceedGuests)"
                                class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-50">
                            <span x-text="guestIsLast ? 'Continue to add-ons' : 'Next'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============ Step 3 · Add-ons ============ --}}
        <section x-show="step === 3" x-cloak class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-brand-900">Add-ons</h2>

            <template x-if="! hasSsr">
                <p class="text-sm text-gray-500">No baggage or meal add-ons are available for this fare.</p>
            </template>

            {{-- Two summary cards per passenger, not two grids of tiles. With six
                 guests the inline grid became a wall of options nobody could scan;
                 this keeps each passenger to two lines and moves the choosing into a
                 picker that opens on demand. --}}
            <template x-for="(p, i) in passengers" :key="i">
                <div x-show="hasSsr" class="rounded-xl border border-gray-200 p-4">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="text-sm font-semibold text-brand-900">
                            <span x-text="p.firstName || ('Guest ' + (i + 1))"></span>
                            <span class="ml-1.5 text-xs font-normal uppercase tracking-wide text-gray-400" x-text="p.type"></span>
                        </p>
                        <p x-show="passengerAddOnTotal(p) > 0" x-cloak class="shrink-0 text-sm font-semibold text-brand-900">
                            +<span x-text="currency"></span> <span x-text="money(passengerAddOnTotal(p))"></span>
                        </p>
                    </div>

                    <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        {{-- Baggage. Infants have no allowance to extend. --}}
                        <button type="button" x-show="ssr.baggage.length && p.type !== 'Infant'"
                                @click="openAddOnPicker(i, 'baggage')"
                                :class="addOnTileClass(addOnChosen(p, 'baggage'))">
                            <span :class="addOnIconClass(addOnChosen(p, 'baggage'))">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25M16.5 6.144V5.25A2.25 2.25 0 0014.25 3h-4.5A2.25 2.25 0 007.5 5.25v.894m9 0a48.667 48.667 0 00-9 0m9 0a48.11 48.11 0 013.413.387c1.07.16 1.837 1.094 1.837 2.175v2.183a2.18 2.18 0 01-.75 1.661m-15-6.406a48.114 48.114 0 00-3.413.387C2.767 8.13 2 9.064 2 10.145v2.183c0 .655.286 1.253.75 1.661" />
                                </svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-baseline justify-between gap-2">
                                    <span class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Checked baggage</span>
                                    <span class="shrink-0 text-[11px] font-medium text-blue-600"
                                          x-text="addOnChosen(p, 'baggage') ? 'Change' : 'Select'"></span>
                                </span>

                                {{-- Always: the allowance in the fare is what an agent
                                     is actually asked about. --}}
                                <span class="mt-0.5 block text-sm font-semibold text-brand-900"
                                      x-text="includedBaggage ? includedBaggage + ' included in this fare' : 'No allowance in this fare'"></span>

                                <template x-if="! addOnChosen(p, 'baggage')">
                                    <span class="block text-[11px] text-gray-500">No extra baggage added</span>
                                </template>

                                <template x-for="line in addOnLines(p, 'baggage')" :key="line.key">
                                    <span x-show="addOnChosen(p, 'baggage')" class="mt-1 flex items-baseline gap-2 text-[11px]">
                                        <span class="w-20 shrink-0 text-gray-400" x-text="line.route"></span>
                                        <span class="min-w-0 flex-1 truncate font-semibold"
                                              :class="line.label ? 'text-brand-900' : 'text-gray-400'"
                                              x-text="line.label ?? 'none added'"></span>
                                        <span x-show="line.label" class="shrink-0 font-medium text-gray-600"
                                              x-text="addOnPriceLabel(line.price)"></span>
                                    </span>
                                </template>
                            </span>
                        </button>

                        {{-- Meal --}}
                        <button type="button" x-show="ssr.meals.length"
                                @click="openAddOnPicker(i, 'meal')"
                                :class="addOnTileClass(addOnChosen(p, 'meal'))">
                            <span :class="addOnIconClass(addOnChosen(p, 'meal'))">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 12.5h16a8 8 0 01-16 0zM2.5 21h19" />
                                    <path stroke-linecap="round" d="M9.2 4c0 1.1-1 1.6-1 2.7s1 1.6 1 1.6M13.4 3.4c0 1.3-1.2 1.9-1.2 3.1s1.2 1.8 1.2 1.8" />
                                </svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-baseline justify-between gap-2">
                                    <span class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Meal</span>
                                    <span class="shrink-0 text-[11px] font-medium text-blue-600"
                                          x-text="addOnChosen(p, 'meal') ? 'Change' : 'Select'"></span>
                                </span>

                                <template x-if="! addOnChosen(p, 'meal')">
                                    <span class="mt-0.5 block text-sm font-semibold text-brand-900">No meal ordered</span>
                                </template>

                                <template x-for="line in addOnLines(p, 'meal')" :key="line.key">
                                    <span x-show="addOnChosen(p, 'meal')" class="mt-1 flex items-baseline gap-2 text-[11px]">
                                        <span class="w-20 shrink-0 text-gray-400" x-text="line.route"></span>
                                        <span class="min-w-0 flex-1 truncate font-semibold"
                                              :class="line.label ? 'text-brand-900' : 'text-gray-400'"
                                              x-text="line.label ?? 'none ordered'"></span>
                                        <span x-show="line.label" class="shrink-0 font-medium text-gray-600"
                                              x-text="addOnPriceLabel(line.price)"></span>
                                    </span>
                                </template>
                            </span>
                        </button>
                    </div>
                </div>
            </template>

            <div x-show="ancillaryTotal > 0" x-cloak class="flex items-center justify-between rounded-lg bg-gray-50 px-4 py-2.5 text-sm">
                <span class="text-gray-600">Add-ons total</span>
                <span class="font-semibold text-brand-900"><span x-text="currency"></span> <span x-text="money(ancillaryTotal)"></span></span>
            </div>

            <div class="flex justify-between border-t border-gray-100 pt-4">
                <button type="button" @click="back()" class="text-sm font-medium text-gray-600 hover:text-gray-800">&larr; Back</button>
                <button type="button" @click="next()" class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">Continue to payment</button>
            </div>

            {{-- The picker. One dialog shared by every card, holding a *draft* choice
                 that only lands on the passenger when Select is pressed — so an agent
                 browsing options in front of a client cannot change the price by
                 accident, and Cancel genuinely undoes. --}}
            <div x-show="addOnPicker" x-cloak
                 class="fixed inset-0 z-50 flex items-end justify-center bg-gray-900/40 p-0 sm:items-center sm:p-4"
                 @click.self="cancelAddOnPicker()" @keydown.escape.window="cancelAddOnPicker()"
                 role="dialog" aria-modal="true" aria-labelledby="addon-picker-title">
                <div class="flex max-h-[85vh] w-full max-w-lg flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
                    <header class="border-b border-gray-100 px-5 py-4">
                        <h3 id="addon-picker-title" class="text-sm font-semibold text-brand-900" x-text="addOnPickerTitle"></h3>
                        <p class="mt-0.5 text-xs text-gray-500" x-text="addOnPickerSubtitle"></p>
                    </header>

                    {{-- A tab per leg. TBO prices these per leg and repeats the same
                         option across them, so one flat list forced a choice for one
                         leg and silently left the rest empty. --}}
                    {{-- shrink-0: without it the modal's flex column squeezes this bar
                         and clips the second line off every tab. --}}
                    <div x-show="addOnPickerTabs.length > 1" class="flex shrink-0 gap-1 overflow-x-auto border-b border-gray-100 px-3 pt-2">
                        <template x-for="tab in addOnPickerTabs" :key="tab.key">
                            <button type="button" @click="addOnPicker.activeLeg = tab.key"
                                    :aria-selected="addOnPicker.activeLeg === tab.key" role="tab"
                                    :class="'shrink-0 border-b-2 px-3 pb-2 pt-1 text-left transition ' + (
                                        addOnPicker.activeLeg === tab.key
                                            ? 'border-blue-600 text-brand-900'
                                            : 'border-transparent text-gray-500 hover:text-gray-700')">
                                <span class="flex items-center gap-1.5">
                                    <span class="text-xs font-semibold" x-text="tab.route"></span>
                                    {{-- A filled dot means this leg already has something on it. --}}
                                    <span x-show="tab.chosen" class="h-1.5 w-1.5 rounded-full bg-blue-600"></span>
                                </span>
                                <span x-show="tab.label" class="block text-[10px] text-gray-400" x-text="tab.label"></span>
                            </button>
                        </template>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto p-3">
                        <div class="space-y-1.5" role="radiogroup"
                             :aria-label="addOnPickerActiveLeg ? addOnPickerActiveLeg.route : addOnPickerTitle">
                            <button type="button" role="radio" :aria-checked="! addOnPicker.draft[addOnPicker.activeLeg]"
                                    @click="addOnPicker.draft[addOnPicker.activeLeg] = ''"
                                    :class="addOnRowClass(! addOnPicker.draft[addOnPicker.activeLeg])">
                                <span :class="addOnDotClass(! addOnPicker.draft[addOnPicker.activeLeg])"></span>
                                <span class="min-w-0 flex-1 text-sm font-medium text-brand-900" x-text="addOnPickerNoneTitle"></span>
                                <span class="shrink-0 text-sm text-gray-400">&mdash;</span>
                            </button>

                            <template x-for="o in addOnPickerActiveOptions" :key="o.key">
                                <button type="button" role="radio" :aria-checked="addOnPicker.draft[addOnPicker.activeLeg] === o.key"
                                        @click="addOnPicker.draft[addOnPicker.activeLeg] = o.key"
                                        :class="addOnRowClass(addOnPicker.draft[addOnPicker.activeLeg] === o.key)">
                                    <span :class="addOnDotClass(addOnPicker.draft[addOnPicker.activeLeg] === o.key)"></span>
                                    <span class="min-w-0 flex-1 text-sm font-medium text-brand-900" x-text="o.label"></span>
                                    <span class="shrink-0 text-sm font-semibold"
                                          :class="Number(o.price) > 0 ? 'text-brand-900' : 'text-emerald-600'"
                                          x-text="addOnPriceLabel(o.price)"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <footer class="flex items-center justify-between gap-3 border-t border-gray-100 px-5 py-3">
                        <p class="text-xs text-gray-500">
                            Selected:
                            <span class="font-semibold text-brand-900" x-text="addOnPickerDraftLabel"></span>
                        </p>
                        <div class="flex shrink-0 gap-2">
                            <button type="button" @click="cancelAddOnPicker()"
                                    class="rounded-lg border border-gray-300 bg-white px-4 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                                Cancel
                            </button>
                            <button type="button" @click="confirmAddOnPicker()"
                                    class="rounded-lg bg-blue-600 px-5 py-1.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                                Select
                            </button>
                        </div>
                    </footer>
                </div>
            </div>
        </section>

        {{-- ============ Step 4 · Payment ============ --}}
        <section x-show="step === 4" x-cloak class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-brand-900">Payment</h2>

            <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">
                Payment integration is coming soon. For now, confirm to save your booking as a quote.
            </div>

            <div class="rounded-lg border border-gray-100 text-sm">
                <div class="flex items-center justify-between px-4 py-2"><span class="text-gray-500">Fare</span><span class="text-brand-900"><span x-text="currency"></span> <span x-text="money(quote.price.offeredFare)"></span></span></div>
                <div x-show="ancillaryTotal > 0" x-cloak class="flex items-center justify-between border-t border-gray-100 px-4 py-2"><span class="text-gray-500">Add-ons</span><span class="text-brand-900"><span x-text="currency"></span> <span x-text="money(ancillaryTotal)"></span></span></div>
                <div class="flex items-center justify-between border-t border-gray-100 px-4 py-2.5"><span class="font-semibold text-brand-900">Total</span><span class="text-base font-bold text-brand-900"><span x-text="currency"></span> <span x-text="money(grandTotal)"></span></span></div>

                {{-- Wallet lines: absent for platform staff, who are not charged. --}}
                <template x-if="hasWallet">
                    <div>
                        <div class="flex items-center justify-between border-t border-gray-100 px-4 py-2">
                            <span class="text-gray-500">Wallet balance</span>
                            <span class="text-brand-900"><span x-text="currency"></span> <span x-text="money(walletBalance)"></span></span>
                        </div>
                        <div class="flex items-center justify-between border-t border-gray-100 px-4 py-2">
                            <span class="text-gray-500">Remaining after booking</span>
                            <span :class="walletShort ? 'font-semibold text-red-700' : 'text-brand-900'">
                                <span x-text="currency"></span> <span x-text="money(walletRemaining)"></span>
                            </span>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Advisory: the server re-checks the balance under lock at submit, so a
                 colleague spending the funds while this page is open is still caught. --}}
            <template x-if="walletShort">
                <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm">
                    <p class="font-semibold text-red-800">Not enough wallet balance</p>
                    <p class="mt-1 text-red-700">
                        This booking needs
                        <span class="font-semibold"><span x-text="currency"></span> <span x-text="money(walletShortfall)"></span></span>
                        more than your agency has available. Load the wallet, then come back — your selection is kept.
                    </p>
                    <template x-if="walletRequestUrl">
                        <a :href="walletRequestUrl"
                           class="mt-3 inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700">
                            Request a load
                        </a>
                    </template>
                </div>
            </template>

            <div x-show="error" x-cloak class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700" x-text="error"></div>

            <x-live-warning :live="$isLive">
                Completing it charges the agency and issues a real ticket with the airline.
            </x-live-warning>

            <div class="flex justify-between border-t border-gray-100 pt-4">
                <button type="button" @click="back()" class="text-sm font-medium text-gray-600 hover:text-gray-800">&larr; Back</button>
                <button type="button" @click="complete()" :disabled="submitting || walletShort"
                        :title="walletShort ? 'Not enough wallet balance for this booking' : null"
                        class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">
                    <svg x-show="submitting" x-cloak class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                    <span x-text="submitting ? 'Confirming…' : 'Complete booking'"></span>
                </button>
            </div>
        </section>

        {{-- ============ Step 5 · Confirmation ============ --}}
        <section x-show="step === 5" x-cloak class="rounded-xl border border-gray-200 bg-white p-8 text-center shadow-sm">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            </div>
            <h2 class="mt-4 text-lg font-bold text-brand-900">Booking confirmed</h2>
            <p class="mt-1 text-sm text-gray-500">Reference <span class="font-mono font-semibold text-brand-900" x-text="reference"></span> — we are contacting the airline now. Open the booking to watch it complete; the ticket numbers appear there when it is done.</p>
            <div class="mt-6 flex items-center justify-center gap-3">
                <a :href="showUrl" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">View booking</a>
                <a :href="flightsUrl" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50">Book another</a>
            </div>
        </section>

        {{-- Price-change gate — shown on load if the re-price differs from the searched fare --}}
        <div x-show="priceGateOpen" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center">
            <div class="absolute inset-0 bg-black/40"></div>
            <div class="relative z-10 max-h-[90vh] w-full max-w-md overflow-y-auto rounded-t-2xl bg-white p-6 shadow-xl sm:rounded-2xl"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-4"
                 x-transition:enter-end="opacity-100 translate-y-0">

                <h2 class="text-base font-semibold text-brand-900">Fare price updated</h2>
                <p class="mt-0.5 text-sm text-gray-500">This fare changed since your search. Continue only if you accept the new price.</p>

                <div class="mt-4 space-y-2 rounded-lg border border-gray-100 p-4 text-sm">
                    <div class="flex items-center justify-between" x-show="oldFare > 0">
                        <span class="text-gray-500">Previous fare</span>
                        <span class="text-gray-400 line-through"><span x-text="currency"></span> <span x-text="money(oldFare)"></span></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="font-medium text-brand-900">New fare</span>
                        <span class="font-semibold text-brand-900"><span x-text="currency"></span> <span x-text="money(quote.price.offeredFare)"></span></span>
                    </div>
                    <div class="flex items-center justify-between border-t border-gray-100 pt-2" x-show="oldFare > 0">
                        <span class="text-gray-500">Difference</span>
                        <span class="font-semibold" :class="priceDiff >= 0 ? 'text-red-600' : 'text-emerald-600'">
                            <span x-text="priceDiff >= 0 ? '+' : '−'"></span><span x-text="currency"></span> <span x-text="money(Math.abs(priceDiff))"></span>
                        </span>
                    </div>
                </div>

                <div x-show="quote?.fareBreakdown?.length" x-cloak class="mt-3">
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">New fare breakdown</p>
                    <div class="divide-y divide-gray-100 rounded-lg border border-gray-100 text-sm">
                        <template x-for="(b, i) in quote.fareBreakdown" :key="i">
                            <div class="flex items-center justify-between px-3 py-2">
                                <span class="text-gray-600"><span x-text="b.count"></span> × <span x-text="b.passengerType"></span></span>
                                <span class="text-brand-900"><span x-text="currency"></span> <span x-text="money((b.baseFare + b.tax) * b.count)"></span></span>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end gap-3">
                    <button type="button" @click="declinePrice()" class="text-sm font-medium text-gray-600 hover:text-gray-800">Decline &amp; search again</button>
                    <button type="button" @click="acceptPrice()" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">Accept &amp; continue</button>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
