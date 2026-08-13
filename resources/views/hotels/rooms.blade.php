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
                 recognises the property before reading a rate. Four here, the rest
                 behind the viewer — TBO sends dozens and a wall of them is not a page. --}}
            @if (! empty($images))
                <div x-data="photoViewer(@js($images))">
                    <div class="relative overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                        <div class="grid grid-cols-4 gap-1 p-1">
                            <button type="button" @click="show(0)"
                                    class="group col-span-4 sm:col-span-3">
                                <img src="{{ $images[0] }}" alt="{{ $hotel->name }}"
                                     class="h-64 w-full rounded-lg object-cover transition group-hover:brightness-95 sm:h-72" loading="lazy">
                            </button>
                            <div class="col-span-4 grid grid-cols-4 gap-1 sm:col-span-1 sm:grid-cols-1">
                                @foreach (array_slice($images, 1, 3) as $i => $image)
                                    <button type="button" @click="show({{ $i + 1 }})" class="group">
                                        <img src="{{ $image }}" alt=""
                                             class="h-20 w-full rounded-lg object-cover transition group-hover:brightness-95 sm:h-[5.75rem]" loading="lazy">
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        @if (count($images) > 4)
                            <button type="button" @click="show(0)"
                                    class="absolute bottom-4 left-4 inline-flex items-center gap-1.5 rounded-lg bg-white/95 px-3 py-1.5 text-sm font-semibold text-brand-900 shadow-sm ring-1 ring-black/5 transition hover:bg-white">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z" />
                                </svg>
                                See all {{ count($images) }} photos
                            </button>
                        @endif
                    </div>

                    {{-- The viewer. Fixed and above the app chrome, which is z-40 at its
                         highest, so nothing of the page shows through it. --}}
                    <div x-show="open" x-cloak
                         @keydown.escape.window="close()"
                         @keydown.arrow-right.window="next()"
                         @keydown.arrow-left.window="prev()"
                         {{-- No enter transition: paired with x-show it left the panel
                              stuck at opacity-0 and display:none, which is a lightbox
                              that does not open. A fade is not worth that. --}}
                         class="fixed inset-0 z-50 flex flex-col bg-black/90">

                        <div class="flex shrink-0 items-center justify-between px-4 py-3 text-white">
                            <p class="text-sm font-medium">
                                {{ $hotel->name }}
                                <span class="ml-2 text-white/60" x-text="(index + 1) + ' / ' + images.length"></span>
                            </p>
                            <button type="button" @click="close()" aria-label="Close"
                                    class="rounded-lg p-2 text-white/70 transition hover:bg-white/10 hover:text-white">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {{-- Clicking the backdrop closes; clicking the photograph does not,
                             or every attempt to look closely would dismiss it. --}}
                        <div class="flex min-h-0 flex-1 items-center justify-center px-4" @click="close()">
                            <button type="button" @click.stop="prev()" aria-label="Previous"
                                    class="mr-2 shrink-0 rounded-full bg-white/10 p-2 text-white transition hover:bg-white/20 sm:mr-4 sm:p-3">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                                </svg>
                            </button>

                            {{-- Fills the stage and letterboxes inside it. max-h-full alone
                                 refuses to upscale, which is correct for a thumbnail and
                                 wrong here: TBO's gallery mixes sizes, and one 500px frame
                                 sat as a postage stamp in the middle of the screen. Most
                                 are near a thousand pixels, so the stretch is slight. --}}
                            <img :src="images[index]" :alt="'Photo ' + (index + 1)" @click.stop
                                 class="h-full w-full rounded-lg object-contain">

                            <button type="button" @click.stop="next()" aria-label="Next"
                                    class="ml-2 shrink-0 rounded-full bg-white/10 p-2 text-white transition hover:bg-white/20 sm:ml-4 sm:p-3">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                </svg>
                            </button>
                        </div>

                        {{-- Filmstrip. Rendered only while the viewer is open, so a hotel
                             with sixty photographs does not fetch sixty thumbnails to sit
                             behind a page nobody opened. --}}
                        <div class="shrink-0 overflow-x-auto px-4 py-3">
                            <div class="flex gap-2">
                                <template x-for="(image, i) in images" :key="i">
                                    <button type="button" @click="show(i)" class="shrink-0">
                                        <img :src="image" alt="" loading="lazy"
                                             class="h-14 w-20 rounded object-cover transition"
                                             :class="i === index ? 'ring-2 ring-white' : 'opacity-50 hover:opacity-100'">
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Each section is gated on the same $sections the tab strip is built
                 from, so a tab and its target cannot disagree about whether it exists.
                 They already did once: Location grew attractions and a phone number,
                 the tab knew and the section did not. --}}
            {{-- Sanitised server-side; TBO writes it as HTML. --}}
            @if (isset($sections['overview']))
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

                                    {{-- The other half of "free until": what it costs after. That
                                         is the half that loses money, and it was sitting unused in
                                         the response. --}}
                                    @if (! empty($room['cancellationSchedule']))
                                        <ul class="mt-2 space-y-0.5 text-xs text-gray-500">
                                            @foreach ($room['cancellationSchedule'] as $policy)
                                                <li>
                                                    @if ($policy['room'])<span class="text-gray-400">Room {{ $policy['room'] }} ·</span>@endif
                                                    Cancel from {{ \Illuminate\Support\Carbon::parse($policy['from'])->format('j M Y') }}:
                                                    <span class="font-medium text-brand-900">
                                                        @if ((float) $policy['charge'] <= 0)
                                                            no charge
                                                        @elseif ($policy['chargeType'] === 'Percentage')
                                                            {{ rtrim(rtrim(number_format((float) $policy['charge'], 2), '0'), '.') }}% of the stay
                                                        @else
                                                            {{ $currency }} {{ number_format((float) $policy['charge'], 2) }}
                                                        @endif
                                                    </span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

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
                                    {{-- What one night costs. An agent quoting a client is asked
                                         this constantly, and it is already in the response. --}}
                                    @if ($room['nightlyRate'] !== null && $stay['nights'] > 1)
                                        <p class="text-xs text-gray-500">
                                            {{ $currency }} {{ number_format($room['nightlyRate'], 2) }} × {{ $stay['nights'] }} nights
                                        </p>
                                    @endif

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

            @if (isset($sections['facilities']))
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
            @if (isset($sections['location']))
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

                    {{-- What a client asks about before they ask about the room. Held since
                         the catalogue was built and never shown until now. --}}
                    @if (! empty($hotel->attractions))
                        <div class="mt-5 border-t border-gray-100 pt-4">
                            <p class="text-sm font-medium text-brand-900">What's nearby</p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach (array_slice($hotel->attractions, 0, 18) as $attraction)
                                    <span class="rounded bg-gray-50 px-2 py-0.5 text-xs text-gray-600 ring-1 ring-inset ring-gray-200">{{ $attraction }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- The property's own line. An agent chasing a late arrival or a special
                         request has to phone the hotel, and nothing else here gives them the
                         number. --}}
                    @if (filled($hotel->phone) || filled($hotel->email) || filled($hotel->website))
                        <div class="mt-5 border-t border-gray-100 pt-4">
                            <p class="text-sm font-medium text-brand-900">Contact the property</p>
                            <dl class="mt-2 space-y-1 text-sm">
                                @if (filled($hotel->phone))
                                    <div class="flex gap-2">
                                        <dt class="w-16 shrink-0 text-gray-500">Phone</dt>
                                        <dd><a href="tel:{{ $hotel->phone }}" class="text-blue-600 hover:text-blue-700">{{ $hotel->phone }}</a></dd>
                                    </div>
                                @endif
                                @if (filled($hotel->email))
                                    <div class="flex gap-2">
                                        <dt class="w-16 shrink-0 text-gray-500">Email</dt>
                                        <dd class="min-w-0 break-all"><a href="mailto:{{ $hotel->email }}" class="text-blue-600 hover:text-blue-700">{{ $hotel->email }}</a></dd>
                                    </div>
                                @endif
                                @if (filled($hotel->website))
                                    <div class="flex gap-2">
                                        <dt class="w-16 shrink-0 text-gray-500">Website</dt>
                                        <dd class="min-w-0 break-all">
                                            <a href="{{ $hotel->website }}" target="_blank" rel="noopener noreferrer"
                                               class="text-blue-600 hover:text-blue-700">{{ $hotel->website }}</a>
                                        </dd>
                                    </div>
                                @endif
                            </dl>
                        </div>
                    @endif
                </div>
            @endif

            {{-- What the property expects of the guest. Cancellation is deliberately not
                 repeated here: it differs per rate, and a single figure on this page
                 would contradict the rates above it. --}}
            @if (isset($sections['policies']))
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
