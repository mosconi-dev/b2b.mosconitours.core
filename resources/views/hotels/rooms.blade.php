<x-app-layout>
    <x-slot name="header">
        <x-page-heading title="Book your stay" subtitle="Complete the steps below to confirm your booking." />
    </x-slot>

    {{-- Search context, carried from the results page and editable in place — the same
         arrangement the flight wizard uses. "Modify" expands the real search form,
         which is the shared partial, and submitting hands off to the results page. --}}
    <div class="mb-6"
         x-data="hotelSearch({
            suggestUrl: '{{ route('hotels.suggest') }}',
            searchUrl: '{{ route('hotels.search') }}',
            redirectUrl: '{{ route('hotels') }}',
            embedded: true,
         })"
         x-init="
            locationLabel = @js($stay['label']);
            locationType = 'city';
            locationCode = @js($stay['from']);
            checkIn = @js($stay['checkIn']);
            checkOut = @js($stay['checkOut']);
            guestNationality = @js($stay['guestNationality']);
            rooms = @js($stay['rooms']);
         ">
        <div x-show="collapsed" x-cloak
             class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21" />
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-brand-900">{{ $stay['summary'] }}</p>
                    <p class="text-xs text-gray-400">Modify to change your search</p>
                </div>
            </div>
            <button type="button" @click="collapsed = false"
                    class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                </svg>
                Modify
            </button>
        </div>

        @include('hotels.form')
    </div>

    {{-- Progress --}}
    <div class="mb-6 rounded-xl border border-gray-200 bg-white px-4 py-5 shadow-sm">
        <x-hotel-stepper :current="2" />
    </div>

    {{-- Selected property. Sticky under the app bar, because it carries the way back
         and the section tabs — both of which are wanted from anywhere on a long page.
         z-10 keeps it beneath the app header rather than over it. --}}
    <div x-data="hotelSections()" class="sticky top-16 z-10 mb-6 rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex items-start justify-between gap-4 p-4">
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-brand-900">{{ $hotel->name }}</p>
                <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-xs text-gray-500">
                    @if ($hotel->rating)
                        <span class="text-amber-500">@for ($i = 0; $i < (int) $hotel->rating; $i++)★@endfor</span>
                    @endif
                    @if (filled($hotel->address))
                        <span class="truncate">{{ $hotel->address }}</span>
                    @endif
                </p>
            </div>
            <div class="shrink-0 text-right">
                @if ($offer !== null && ! empty($offer['rooms']))
                    <p class="text-lg font-bold text-brand-900">
                        {{ $currency }} {{ number_format((float) $offer['lowestFare'], 2) }}
                    </p>
                @endif
                <a href="{{ $backUrl }}" class="text-xs font-medium text-blue-600 hover:text-blue-700">Change hotel</a>
            </div>
        </div>

        {{-- Jump links. Only the sections this property actually has: a tab pointing at
             an empty page is worse than a missing tab. --}}
        {{-- overflow-y-hidden is not redundant: setting overflow-x alone makes the other
             axis compute to auto, which showed a scrollbar for one stray pixel. --}}
        <nav class="flex gap-1 overflow-x-auto overflow-y-hidden border-t border-gray-100 px-2">
            @foreach ($sections as $id => $label)
                <a href="#{{ $id }}" @click.prevent="goTo('{{ $id }}')"
                   class="shrink-0 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition"
                   :class="active === '{{ $id }}' ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-700'">
                    {{ $label }}
                </a>
            @endforeach
        </nav>
    </div>

    <x-admin.flash />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
        <div class="flex flex-col gap-6 lg:col-span-2">

            {{-- Gallery first, as on any hotel page: the photographs are how an agent
                 recognises the property before reading a rate. --}}
            @if (! empty($images))
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div class="grid grid-cols-4 gap-1 p-1">
                        <img src="{{ $images[0] }}" alt="{{ $hotel->name }}"
                             class="col-span-4 h-64 w-full rounded-lg object-cover sm:col-span-3 sm:h-72" loading="lazy">
                        <div class="col-span-4 grid grid-cols-4 gap-1 sm:col-span-1 sm:grid-cols-1">
                            @foreach (array_slice($images, 1, 3) as $image)
                                <img src="{{ $image }}" alt="" class="h-20 w-full rounded-lg object-cover sm:h-[5.75rem]" loading="lazy">
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            {{-- Sanitised server-side; TBO writes it as HTML. --}}
            @if (filled($hotel->description))
                <div id="overview" data-section="overview" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm" x-data="{ expanded: false }">
                    <h2 class="text-base font-semibold text-brand-900">Overview</h2>
                    <div class="supplier-prose mt-3 text-sm text-gray-600"
                         :class="expanded ? '' : 'max-h-40 overflow-hidden'">
                        {!! \App\Support\SupplierHtml::clean($hotel->description) !!}
                    </div>
                    <button type="button" @click="expanded = !expanded"
                            class="mt-2 text-xs font-medium text-brand-700 hover:text-brand-900"
                            x-text="expanded ? 'Show less' : 'Read more'"></button>
                </div>
            @endif

            {{-- Rooms --}}
            <div id="rooms" data-section="rooms">
            @if ($offer === null || empty($offer['rooms']))
                <div class="rounded-xl border border-gray-200 bg-white p-12 text-center shadow-sm">
                    <p class="text-sm font-medium text-brand-900">No rooms available</p>
                    <p class="mt-1 text-sm text-gray-500">
                        This property has nothing left for these dates and occupancy.
                    </p>
                </div>
            @else
                <div class="space-y-4">
                    @foreach ($offer['rooms'] as $room)
                        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div class="min-w-0">
                                    @foreach ($room['names'] as $name)
                                        <p class="text-sm font-medium text-brand-900">{{ $name }}</p>
                                    @endforeach

                                    <p class="mt-1 text-xs text-gray-500">
                                        {{ $room['mealLabel'] }}@if (filled($room['inclusion'])) · {{ $room['inclusion'] }} @endif
                                    </p>

                                    <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                        @if (filled($room['freeCancellationUntil']))
                                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
                                                Free cancellation before
                                                {{ \Illuminate\Support\Carbon::parse($room['freeCancellationUntil'])->format('j M Y') }}
                                            </span>
                                        @endif
                                        @unless ($room['isRefundable'])
                                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Non-refundable</span>
                                        @endunless
                                        @foreach ($room['promotions'] as $promotion)
                                            <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">{{ $promotion }}</span>
                                        @endforeach
                                    </div>

                                    {{-- What the guest settles at the desk. Shown before booking
                                         because §18 requires it, and because a deposit sprung at
                                         check-in is a complaint we caused. --}}
                                    @if (! empty($room['payableAtProperty']))
                                        <div class="mt-3 rounded-lg bg-amber-50/70 px-3 py-2 text-xs text-amber-900">
                                            <p class="font-medium">Payable at the hotel</p>
                                            @foreach ($room['payableAtProperty'] as $supplement)
                                                <p>
                                                    {{ $supplement['description'] ?? 'Additional charge' }} —
                                                    {{ $supplement['currency'] ?? $currency }}
                                                    {{ number_format((float) ($supplement['price'] ?? 0), 2) }}
                                                </p>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>

                                <div class="shrink-0 text-right">
                                    <p class="text-base font-semibold text-brand-900">
                                        {{ $currency }} {{ number_format((float) $room['totalFare'], 2) }}
                                    </p>
                                    <p class="text-xs text-gray-400">
                                        incl. tax {{ $currency }} {{ number_format((float) $room['totalTax'], 2) }}
                                    </p>

                                    @can('hotel.book')
                                        {{-- Leaves step 2. The wizard re-prices through PreBook
                                             before it renders, so the terms the agent accepts are
                                             the supplier's current ones, not this page's. --}}
                                        <a href="{{ route('hotels.book', [
                                                'bookingCode' => $room['bookingCode'],
                                                'checkIn' => $stay['checkIn'],
                                                'checkOut' => $stay['checkOut'],
                                                'locationCode' => $hotel->code,
                                                'guestNationality' => $stay['guestNationality'],
                                                'rooms' => $stay['roomsToken'],
                                                'shownFare' => $room['totalFare'],
                                            ]) }}"
                                           class="mt-2 inline-block rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                                            Select
                                        </a>
                                    @else
                                        <button type="button" disabled title="You don't have booking permission"
                                                class="mt-2 cursor-not-allowed rounded-lg bg-gray-300 px-4 py-2 text-sm font-semibold text-white">
                                            Select
                                        </button>
                                    @endcan
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
            </div>

            @if (! empty($hotel->facilities))
                <div id="facilities" data-section="facilities" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-brand-900">Facilities</h2>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        @foreach (array_slice($hotel->facilities, 0, 24) as $facility)
                            <span class="rounded bg-gray-50 px-2 py-0.5 text-xs text-gray-600 ring-1 ring-inset ring-gray-200">{{ $facility }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Where it is. No embedded map: a tile provider is an external request on
                 every card view, and the address plus a link out answers the question
                 an agent actually has. --}}
            @if (filled($hotel->address) || $hotel->latitude)
                <div id="location" data-section="location" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-brand-900">Location</h2>
                    @if (filled($hotel->address))
                        <p class="mt-2 text-sm text-gray-600">{{ $hotel->address }}</p>
                    @endif
                    @if ($hotel->latitude && $hotel->longitude)
                        <a href="https://www.google.com/maps/search/?api=1&query={{ $hotel->latitude }},{{ $hotel->longitude }}"
                           target="_blank" rel="noopener noreferrer"
                           class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                            </svg>
                            Open in Google Maps
                        </a>
                    @endif
                </div>
            @endif

            {{-- What the property expects of the guest. Cancellation is deliberately not
                 repeated here: it differs per rate, and a single figure on this page
                 would contradict the rates above it. --}}
            @if (filled($hotel->checkin_time) || filled($hotel->checkout_time) || $payableAtProperty !== [])
                <div id="policies" data-section="policies" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-brand-900">Policies</h2>

                    <dl class="mt-3 grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
                        @if (filled($hotel->checkin_time))
                            <div>
                                <dt class="text-gray-500">Check-in from</dt>
                                <dd class="font-medium text-brand-900">{{ $hotel->checkin_time }}</dd>
                            </div>
                        @endif
                        @if (filled($hotel->checkout_time))
                            <div>
                                <dt class="text-gray-500">Check-out by</dt>
                                <dd class="font-medium text-brand-900">{{ $hotel->checkout_time }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if ($payableAtProperty !== [])
                        <div class="mt-4 border-t border-gray-100 pt-4">
                            <p class="text-sm font-medium text-brand-900">Payable at the hotel</p>
                            <p class="mt-0.5 text-xs text-gray-500">Charges the guest settles on arrival, on top of the rate.</p>
                            <ul class="mt-2 space-y-1 text-sm text-gray-600">
                                @foreach ($payableAtProperty as $description)
                                    <li>{{ $description }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <p class="mt-4 border-t border-gray-100 pt-4 text-xs text-gray-500">
                        Cancellation terms differ by rate — each rate above states its own, and the
                        one shown at payment is the one that governs the booking.
                    </p>
                </div>
            @endif
        </div>

        {{-- The stay --}}
        {{-- top comes from the header's measured height (see hotelSections), because a
             fixed offset is wrong the day the header gains a line. --}}
        <aside class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm lg:sticky"
               style="top: calc(var(--hotel-header, 12rem) + var(--hotel-gap))">
            <h2 class="text-sm font-semibold text-brand-900">Your stay</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Check-in</dt>
                    <dd class="font-medium text-brand-900">{{ \Illuminate\Support\Carbon::parse($stay['checkIn'])->format('j M Y') }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Check-out</dt>
                    <dd class="font-medium text-brand-900">{{ \Illuminate\Support\Carbon::parse($stay['checkOut'])->format('j M Y') }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Stay</dt>
                    <dd class="font-medium text-brand-900">
                        {{ $stay['nights'] }} {{ \Illuminate\Support\Str::plural('night', $stay['nights']) }} ·
                        {{ count($stay['rooms']) }} {{ \Illuminate\Support\Str::plural('room', count($stay['rooms'])) }}
                    </dd>
                </div>
                @if (filled($hotel->checkin_time))
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Check-in from</dt>
                        <dd class="font-medium text-brand-900">{{ $hotel->checkin_time }}</dd>
                    </div>
                @endif
                @if (filled($hotel->checkout_time))
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Check-out by</dt>
                        <dd class="font-medium text-brand-900">{{ $hotel->checkout_time }}</dd>
                    </div>
                @endif
            </dl>
        </aside>
    </div>
</x-app-layout>
