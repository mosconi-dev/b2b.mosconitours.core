<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-bold tracking-tight text-brand-900">Book your flight</h1>
        <p class="mt-1 text-sm text-gray-500">Complete the steps below to confirm your booking.</p>
    </x-slot>

    <div x-data="bookingWizard(@js([
            'traceId' => $traceId,
            'resultIndex' => $resultIndex,
            'quote' => $quote,
            'ssr' => $ssr,
            'summary' => $summary,
            'oldFare' => $oldFare,
            'bookingUrl' => route('bookings.store'),
            'flightsUrl' => route('flights'),
         ]))">

        {{-- Search context — carried from the flights search. Editable in place:
             "Edit search" expands the real search form right here; submitting it
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

                        {{-- The real search form (shared with the flights page). Expands
                             when the user hits Edit search; "Search Flights" navigates to
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
        <div class="mb-6 flex items-center justify-between rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div>
                <p class="text-sm font-semibold text-brand-900">
                    <span x-text="summary.airline || 'Selected flight'"></span>
                    <span x-show="summary.from && summary.to" class="text-gray-400"> · <span x-text="summary.from"></span> → <span x-text="summary.to"></span></span>
                </p>
                <p class="mt-0.5 text-xs text-gray-500">
                    <span x-text="quote.isLcc ? 'Low-cost' : 'GDS'"></span> ·
                    <span x-text="quote.isRefundable ? 'Refundable' : 'Non-refundable'"></span>
                </p>
            </div>
            <div class="text-right">
                <p class="text-lg font-bold text-brand-900"><span x-text="currency"></span> <span x-text="money(grandTotal)"></span></p>
                <a :href="flightsUrl" x-show="step < 5" class="text-xs font-medium text-blue-600 hover:text-blue-700">Change flight</a>
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
                                <input type="email" x-model="contact.email" placeholder="agent@email.com" class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-600">Contact number</label>
                                <div class="flex">
                                    <span class="inline-flex items-center rounded-l-lg border border-r-0 border-gray-300 bg-gray-50 px-3 text-sm text-gray-500">+63</span>
                                    <input type="tel" x-model="contact.phone" placeholder="917 123 4567" class="w-full rounded-r-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500" />
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Per-guest details --}}
                    <template x-for="(p, i) in passengers" :key="i">
                        <div x-show="guestTab === i" class="space-y-4">
                            <div>
                                <h2 class="text-base font-semibold text-brand-900">Guest <span x-text="i + 1"></span> <span class="font-normal text-gray-400">· <span x-text="p.type"></span></span></h2>
                                <p x-show="quote.isPassportMandatory" x-cloak class="mt-0.5 text-xs text-blue-700">Passport details are required for this fare.</p>
                            </div>

                            <div class="grid grid-cols-2 gap-4 sm:grid-cols-12">
                                <div class="col-span-2 sm:col-span-2">
                                    <label class="mb-1 block text-xs font-medium text-gray-600">Title</label>
                                    <select x-model="p.title" class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option>Mr</option><option>Mrs</option><option>Ms</option><option>Mstr</option><option>Miss</option>
                                    </select>
                                </div>
                                <div class="col-span-1 sm:col-span-5">
                                    <label class="mb-1 block text-xs font-medium text-gray-600">First name</label>
                                    <input type="text" x-model="p.firstName" placeholder="First name" class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500" />
                                </div>
                                <div class="col-span-1 sm:col-span-5">
                                    <label class="mb-1 block text-xs font-medium text-gray-600">Last name</label>
                                    <input type="text" x-model="p.lastName" placeholder="Last name" class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-600">Gender</label>
                                    <select x-model="p.gender" class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="">Select</option><option value="M">Male</option><option value="F">Female</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-600">Date of birth</label>
                                    <input type="date" x-model="p.dateOfBirth" class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500" />
                                </div>
                            </div>

                            <div x-show="quote.isPassportMandatory" x-cloak class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-600">Passport no.</label>
                                    <input type="text" x-model="p.passportNo" placeholder="Passport no." class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-600">Passport expiry</label>
                                    <input type="date" x-model="p.passportExpiry" class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-600">Nationality</label>
                                    <input type="text" x-model="p.nationality" maxlength="2" placeholder="PH" class="w-full rounded-lg border-gray-300 text-sm uppercase focus:border-blue-500 focus:ring-blue-500" />
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Footer --}}
                    <div class="mt-6 flex items-center justify-between border-t border-gray-100 pt-4">
                        <button type="button" x-show="guestActiveIndex > 0" x-cloak @click="guestRetreat()" class="text-sm font-medium text-gray-600 hover:text-gray-800">&larr; Back</button>
                        <a :href="flightsUrl" x-show="guestActiveIndex === 0" x-cloak class="text-sm font-medium text-gray-500 hover:text-gray-700">Change flight</a>
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

            <template x-for="(p, i) in passengers" :key="i">
                <div x-show="hasSsr" class="rounded-lg border border-gray-200 p-3">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Guest <span x-text="i + 1"></span> · <span x-text="p.firstName || p.type"></span></p>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <select x-show="ssr.baggage.length && p.type !== 'Infant'" x-model="p.baggage" class="rounded-lg border-gray-300 py-1.5 text-sm">
                            <option value="">No extra baggage</option>
                            <template x-for="b in ssr.baggage" :key="b.code">
                                <option :value="b.code" x-text="b.label + ' — ' + currency + ' ' + money(b.price)"></option>
                            </template>
                        </select>
                        <select x-show="ssr.meals.length" x-model="p.meal" class="rounded-lg border-gray-300 py-1.5 text-sm">
                            <option value="">No meal</option>
                            <template x-for="m in ssr.meals" :key="m.code">
                                <option :value="m.code" x-text="m.label + ' — ' + currency + ' ' + money(m.price)"></option>
                            </template>
                        </select>
                    </div>
                </div>
            </template>

            <p x-show="ancillaryTotal > 0" x-cloak class="text-sm text-gray-600">Add-ons: <span class="font-semibold text-brand-900"><span x-text="currency"></span> <span x-text="money(ancillaryTotal)"></span></span></p>

            <div class="flex justify-between border-t border-gray-100 pt-4">
                <button type="button" @click="back()" class="text-sm font-medium text-gray-600 hover:text-gray-800">&larr; Back</button>
                <button type="button" @click="next()" class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">Continue to payment</button>
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
            </div>

            <div x-show="error" x-cloak class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700" x-text="error"></div>

            <div class="flex justify-between border-t border-gray-100 pt-4">
                <button type="button" @click="back()" class="text-sm font-medium text-gray-600 hover:text-gray-800">&larr; Back</button>
                <button type="button" @click="complete()" :disabled="submitting" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60">
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
            <h2 class="mt-4 text-lg font-bold text-brand-900">Booking saved</h2>
            <p class="mt-1 text-sm text-gray-500">Reference <span class="font-mono font-semibold text-brand-900" x-text="reference"></span> — a priced quote. Ticketing follows once payment is enabled.</p>
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
