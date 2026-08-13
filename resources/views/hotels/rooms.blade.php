<x-app-layout>
    <x-slot name="header">
        <x-page-heading :title="$hotel->name" :subtitle="$hotel->address">
            <x-slot name="icon">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21" />
                </svg>
            </x-slot>
        </x-page-heading>
    </x-slot>

    <x-slot name="back">
        <a href="{{ $backUrl }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to results</a>
    </x-slot>

    <div class="space-y-6">
        <x-admin.flash />

        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <x-hotel-stepper :current="2" />
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
            <div class="space-y-6 lg:col-span-2">

                @if ($offer === null || empty($offer['rooms']))
                    <div class="rounded-xl border border-gray-200 bg-white p-12 text-center shadow-sm">
                        <p class="text-sm font-medium text-brand-900">No rooms available</p>
                        <p class="mt-1 text-sm text-gray-500">
                            This property has nothing left for these dates and occupancy.
                        </p>
                        <a href="{{ $backUrl }}" class="mt-4 inline-block text-sm font-medium text-brand-700 hover:text-brand-900">
                            Choose another hotel
                        </a>
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
                                               class="mt-2 inline-block rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-800">
                                                Select
                                            </a>
                                        @else
                                            <button type="button" disabled title="You do not have permission to book hotels"
                                                    class="mt-2 cursor-not-allowed rounded-lg bg-gray-200 px-4 py-2 text-sm font-medium text-gray-500">
                                                Select
                                            </button>
                                        @endcan
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- About the property. Sanitised server-side; TBO writes it as HTML. --}}
                @if (filled($hotel->description))
                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm"
                         x-data="{ expanded: false }">
                        <h2 class="text-base font-semibold text-brand-900">About this property</h2>
                        <div class="supplier-prose mt-3 text-sm text-gray-600"
                             :class="expanded ? '' : 'max-h-40 overflow-hidden'">
                            {!! \App\Support\SupplierHtml::clean($hotel->description) !!}
                        </div>
                        <button type="button" @click="expanded = !expanded"
                                class="mt-2 text-xs font-medium text-brand-700 hover:text-brand-900"
                                x-text="expanded ? 'Show less' : 'Read more'"></button>
                    </div>
                @endif

                @if (! empty($hotel->facilities))
                    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h2 class="text-base font-semibold text-brand-900">Facilities</h2>
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach (array_slice($hotel->facilities, 0, 24) as $facility)
                                <span class="rounded bg-gray-50 px-2 py-0.5 text-xs text-gray-600 ring-1 ring-inset ring-gray-200">{{ $facility }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- The stay, and the property at a glance --}}
            <aside class="space-y-4 lg:sticky lg:top-20">
                @if (! empty($images))
                    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                        <img src="{{ $images[0] }}" alt="{{ $hotel->name }}" class="h-44 w-full object-cover" loading="lazy">
                        @if (count($images) > 1)
                            <div class="grid grid-cols-3 gap-1 p-1">
                                @foreach (array_slice($images, 1, 3) as $image)
                                    <img src="{{ $image }}" alt="" class="h-16 w-full rounded object-cover" loading="lazy">
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center gap-1 text-sm text-amber-500">
                        @for ($i = 0; $i < (int) $hotel->rating; $i++)★@endfor
                    </div>

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
                    </dl>

                    <a href="{{ $backUrl }}"
                       class="mt-4 block w-full rounded-lg border border-gray-300 px-4 py-2 text-center text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                        Choose another hotel
                    </a>
                </div>
            </aside>
        </div>
    </div>
</x-app-layout>
