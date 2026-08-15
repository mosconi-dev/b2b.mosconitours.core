<x-app-layout>
    <x-slot name="header">
        <x-page-heading title="Complete Booking" subtitle="{{ $hotel?->name ?? 'Hotel' }}">
            <x-slot name="icon">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21" />
                </svg>
            </x-slot>
        </x-page-heading>
    </x-slot>

    <div x-data="hotelBooking({
            storeUrl: '{{ route('hotels.bookings.store') }}',
            backUrl: @js($backUrl),
            bookingCode: @js($bookingCode),
            quote: @js($quote),
            stay: @js($stay),
            shownFare: @js($shownFare),
            priceChanged: @js($priceChanged),
            wallet: @js($wallet),
            contactEmail: @js(auth()->user()->email),
         })" class="flex flex-col gap-6">

        <x-admin.flash />

        {{-- Progress --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <x-hotel-stepper />
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
            <div class="flex flex-col gap-6 lg:col-span-2">

                {{-- gap, not space-y, throughout: x-for and x-if leave their <template>
                     tags in the DOM and x-show only hides, so the sibling rule hands the
                     first visible child a margin for something that is not there. It put
                     the first room card 16px below the summary card beside it. --}}
                {{-- ============ Step 3 · Guest Details ============ --}}
                <section x-show="step === 3" x-cloak class="flex flex-col gap-4">
                    <template x-for="(room, r) in rooms" :key="r">
                        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <div class="flex items-baseline justify-between">
                                <h2 class="text-sm font-semibold text-brand-900"
                                    x-text="'Room ' + (r + 1) + (roomName(r) ? ' · ' + roomName(r) : '')"></h2>
                                <span class="text-xs text-gray-400" x-text="occupancyLabel(room)"></span>
                            </div>

                            <div class="mt-4 flex flex-col gap-3">
                                <template x-for="g in guestsIn(r)" :key="g.key">
                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-12">
                                        <div class="sm:col-span-2">
                                            <label class="block text-xs text-gray-500">Title</label>
                                            <select x-model="g.title"
                                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                                <option value="Mr">Mr</option>
                                                <option value="Mrs">Mrs</option>
                                                <option value="Ms">Ms</option>
                                            </select>
                                        </div>
                                        <div class="sm:col-span-4">
                                            <label class="block text-xs text-gray-500">First name</label>
                                            <input type="text" x-model="g.firstName" autocomplete="off"
                                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                        </div>
                                        <div class="sm:col-span-4">
                                            <label class="block text-xs text-gray-500">Last name</label>
                                            <input type="text" x-model="g.lastName" autocomplete="off"
                                                   class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                        </div>
                                        {{-- Labelled like its neighbours rather than bottom-
                                             aligned against them: with no label of its own the
                                             column started higher and the pills sat off the line
                                             the inputs sit on. The label also says what the pill
                                             is, which "Adult" alone does not. --}}
                                        <div class="sm:col-span-2">
                                            <label class="block text-xs text-gray-500">Guest</label>
                                            <div class="mt-1 flex h-[38px] flex-wrap items-center gap-1.5">
                                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600"
                                                      x-text="g.type"></span>
                                                <template x-if="g.isLead">
                                                    <span class="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700">Lead</span>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    {{-- Contact. TBO takes one email and phone for the whole reservation,
                         not one per guest — this is who the hotel writes to. --}}
                    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h2 class="text-sm font-semibold text-brand-900">Contact for this booking</h2>
                        <p class="mt-1 text-xs text-gray-500">Where the hotel and TBO send the confirmation.</p>
                        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <x-input-label for="contact-email" value="Email" />
                                <input id="contact-email" type="email" x-model="contact.email"
                                       class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            </div>
                            <div>
                                <x-input-label for="contact-phone" value="Phone" />
                                <input id="contact-phone" type="text" x-model="contact.phone" placeholder="+63…"
                                       class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <a :href="backUrl" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to rooms</a>
                        <x-primary-button type="button" @click="toPayment()">Continue to payment</x-primary-button>
                    </div>
                    <p x-show="error" x-cloak class="text-right text-sm text-red-600" x-text="error"></p>
                </section>

                {{-- ============ Step 4 · Payment ============ --}}
                <section x-show="step === 4" x-cloak class="flex flex-col gap-4">

                    {{-- The re-price gate. Only rendered when PreBook actually moved the
                         price, and it must be accepted explicitly: booking silently at a
                         new figure spends money on a number nobody saw. --}}
                    <template x-if="priceChanged">
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5">
                            <p class="text-sm font-semibold text-amber-900">The hotel re-priced this room</p>
                            <p class="mt-1 text-sm text-amber-800">
                                Shown at <span class="line-through" x-text="money(shownFare)"></span>,
                                now <span class="font-semibold" x-text="money(quote.totalFare)"></span>
                                (<span x-text="(delta > 0 ? '+' : '') + money(delta)"></span>).
                            </p>
                            <label class="mt-3 inline-flex items-center gap-2 text-sm text-amber-900">
                                <input type="checkbox" x-model="acceptPriceChange"
                                       class="rounded border-amber-300 text-amber-600 focus:ring-amber-500">
                                Charge the new price
                            </label>
                        </div>
                    </template>

                    {{-- Binding terms. §18 makes PreBook's policy and norms final for the
                         itinerary, so this is the copy that governs the booking. --}}
                    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h2 class="text-sm font-semibold text-brand-900">Cancellation</h2>
                        <template x-if="quote.freeCancellationUntil">
                            <p class="mt-1 text-sm text-emerald-700">
                                Free cancellation before <span x-text="formatDay(quote.freeCancellationUntil)"></span>.
                            </p>
                        </template>
                        <template x-if="!quote.freeCancellationUntil">
                            <p class="mt-1 text-sm text-gray-600">This rate is non-refundable.</p>
                        </template>

                        {{-- The schedule, not the bucketed policies: those are keyed by
                             room and iterating them renders one empty row per bucket. --}}
                        <template x-if="quote.cancellationSchedule && quote.cancellationSchedule.length">
                            <ul class="mt-3 flex flex-col gap-1 border-t border-gray-100 pt-3 text-xs text-gray-500">
                                <template x-for="(p, i) in quote.cancellationSchedule" :key="i">
                                    <li>
                                        <template x-if="p.room"><span x-text="'Room ' + p.room + ' · '"></span></template>
                                        From <span class="font-medium text-brand-900" x-text="formatDay(p.from)"></span>:
                                        <span x-text="cancellationCharge(p)"></span>
                                    </li>
                                </template>
                            </ul>
                        </template>
                    </div>

                    {{-- Charges the guest settles at the desk. Shown before booking
                         because §18 requires it, and because a deposit sprung at
                         check-in is a complaint we caused. --}}
                    <template x-if="quote.payableAtProperty && quote.payableAtProperty.length">
                        <div class="rounded-xl border border-amber-200 bg-amber-50/60 p-5">
                            <h2 class="text-sm font-semibold text-amber-900">Payable at the hotel</h2>
                            <p class="mt-1 text-xs text-amber-800">Not included in the amount below — the guest settles this on arrival.</p>
                            <ul class="mt-3 flex flex-col gap-1 text-sm text-amber-900">
                                <template x-for="(s, i) in quote.payableAtProperty" :key="i">
                                    <li class="flex justify-between gap-4">
                                        <span>
                                            <span x-text="s.description"></span>
                                            <template x-if="s.count > 1">
                                                <span class="text-amber-800/70" x-text="' × ' + s.count + ' rooms'"></span>
                                            </template>
                                        </span>
                                        <span class="font-medium" x-text="money(s.total)"></span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    <template x-if="quote.rateConditions && quote.rateConditions.length">
                        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <h2 class="text-sm font-semibold text-brand-900">Hotel conditions</h2>
                            {{-- Several of these are HTML, sanitised server-side. Rendered as
                                 blocks rather than list items because some are lists themselves. --}}
                            <div class="mt-2 flex flex-col gap-2 text-xs text-gray-600">
                                <template x-for="(c, i) in quote.rateConditions" :key="i">
                                    <div class="supplier-prose" x-html="c"></div>
                                </template>
                            </div>
                        </div>
                    </template>

                    <x-live-warning :live="$isLive">
                        Completing it charges the agency and takes a real room the hotel will hold.
                        Cancelling it later may carry a charge.
                    </x-live-warning>

                    <div class="flex items-center justify-between">
                        <button type="button" @click="step = 3" class="text-sm font-medium text-gray-500 hover:text-gray-700">Back to guests</button>
                        {{-- This press is the whole transaction: it charges the agency
                             and takes the room. Worded as the flight wizard words it. --}}
                        <x-primary-button type="button" @click="submit()" ::disabled="loading || !canSubmit">
                            <span x-show="!loading">Complete booking</span>
                            <span x-show="loading" x-cloak>Confirming…</span>
                        </x-primary-button>
                    </div>
                    <p x-show="error" x-cloak class="text-right text-sm text-red-600" x-text="error"></p>
                </section>
            </div>

            {{-- Summary, always visible --}}
            <aside class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm lg:sticky lg:top-[calc(4rem+var(--hotel-gap))]">
                <div>
                    <p class="text-sm font-semibold text-brand-900">{{ $hotel?->name ?? 'Hotel' }}</p>
                    @if ($hotel?->address)
                        <p class="mt-0.5 text-xs text-gray-500">{{ $hotel->address }}</p>
                    @endif
                </div>

                <dl class="space-y-2 border-t border-gray-100 pt-4 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Check-in</dt>
                        <dd class="font-medium text-brand-900" x-text="formatDay(stay.checkIn)"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Check-out</dt>
                        <dd class="font-medium text-brand-900" x-text="formatDay(stay.checkOut)"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Stay</dt>
                        <dd class="font-medium text-brand-900" x-text="nightsLabel"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Nationality</dt>
                        <dd class="font-medium text-brand-900">{{ $stay['nationalityName'] }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Meals</dt>
                        <dd class="font-medium text-brand-900" x-text="quote.mealLabel"></dd>
                    </div>
                </dl>

                <div class="border-t border-gray-100 pt-4">
                    <div class="flex items-baseline justify-between gap-4">
                        <span class="text-sm text-gray-500">Total to charge</span>
                        <span class="flex items-center gap-1.5">
                            <span class="text-lg font-semibold text-brand-900" x-text="money(quote.totalFare)"></span>
                            @include('partials._fare-breakdown', ['pricing' => 'quote.pricing'])
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-gray-400">Includes tax <span x-text="money(quote.totalTax)"></span>.</p>
                </div>

                @if ($wallet)
                    <div class="border-t border-gray-100 pt-4 text-sm">
                        <div class="flex justify-between gap-4">
                            <span class="text-gray-500">Wallet balance</span>
                            <span class="font-medium text-brand-900" x-text="money(wallet.balance)"></span>
                        </div>
                        <template x-if="shortfall > 0">
                            <p class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">
                                Short by <span class="font-semibold" x-text="money(shortfall)"></span>.
                                @if ($walletRequestUrl)
                                    <a href="{{ $walletRequestUrl }}" class="font-medium underline">Request a top-up</a>.
                                @endif
                            </p>
                        </template>
                    </div>
                @endif
            </aside>
        </div>
    </div>
</x-app-layout>
