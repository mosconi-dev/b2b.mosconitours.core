<x-app-layout>
    <x-slot name="header">
        <x-page-heading title="Bookings" subtitle="Your flight and hotel bookings.">
            <x-slot name="icon">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 010 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 010-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375z" />
                </svg>
            </x-slot>
        </x-page-heading>
    </x-slot>

    @php
        // The tabs count what the *search* matches, so the number on a tab is the
        // number of rows pressing it produces.
        $tabs = [
            '' => ['label' => 'All', 'count' => $counts['all']],
            'flight' => ['label' => 'Flights', 'count' => $counts['flight']],
            'hotel' => ['label' => 'Hotels', 'count' => $counts['hotel']],
        ];
        $current = $filters['product']?->value ?? '';
        $isFiltered = $current !== '' || $filters['q'] !== '';
    @endphp

    <div class="space-y-6">
        <x-admin.flash />

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            {{-- Product tabs and search. Both are plain GET links/forms: a filtered
                 list is a URL an agent can bookmark or send to a colleague. --}}
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
                <nav class="inline-flex rounded-lg bg-gray-100 p-1">
                    @foreach ($tabs as $value => $tab)
                        <a href="{{ route('bookings.index', array_filter(['product' => $value, 'q' => $filters['q']])) }}"
                           @class([
                               'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition',
                               'bg-white text-brand-900 shadow-sm' => $current === $value,
                               'text-gray-500 hover:text-brand-900' => $current !== $value,
                           ])>
                            {{ $tab['label'] }}
                            <span @class([
                                'rounded-full px-1.5 py-0.5 text-[11px] font-semibold tabular-nums',
                                'bg-brand-50 text-brand-700' => $current === $value,
                                'bg-gray-200 text-gray-600' => $current !== $value,
                            ])>{{ $tab['count'] }}</span>
                        </a>
                    @endforeach
                </nav>

                <form method="GET" class="flex items-center gap-2">
                    <input type="hidden" name="product" value="{{ $current }}">
                    <x-text-input name="q" value="{{ $filters['q'] }}" type="search"
                                  placeholder="Search reference, PNR, guest or hotel…"
                                  class="w-full text-sm sm:w-80" />
                    <x-secondary-button type="submit">Search</x-secondary-button>
                    @if ($filters['q'] !== '')
                        <a href="{{ route('bookings.index', array_filter(['product' => $current])) }}"
                           class="text-sm font-medium text-gray-500 hover:text-gray-700">Clear</a>
                    @endif
                </form>
            </div>

            @if ($bookings->isEmpty())
                <div class="p-12 text-center">
                    @if ($isFiltered)
                        <p class="text-sm font-medium text-brand-900">No bookings match this filter</p>
                        <p class="mt-1 text-sm text-gray-500">
                            Try a different search, or
                            <a href="{{ route('bookings.index') }}" class="font-medium text-blue-600 hover:text-blue-700">show all bookings</a>.
                        </p>
                    @else
                        <p class="text-sm font-medium text-brand-900">No bookings yet</p>
                        <p class="mt-1 text-sm text-gray-500">Search a flight or a hotel, select a rate and confirm it to create a booking.</p>
                    @endif
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-5 py-3">Booking</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3">Env</th>
                                <th class="px-5 py-3">Travellers</th>
                                <th class="px-5 py-3">Total</th>
                                <th class="px-5 py-3">Created</th>
                                <th class="px-5 py-3 text-right"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($bookings as $booking)
                                @php
                                    $isHotel = $booking->isHotel();
                                    // What was bought, in one line. Flights and hotels keep it in
                                    // different halves of the record, so the model answers it.
                                    $summary = $booking->itinerarySummary();
                                @endphp
                                <tr class="cursor-pointer transition hover:bg-gray-50" onclick="window.location='{{ route('bookings.show', $booking) }}'">
                                    {{-- Reference, product and what was bought, in one cell. Which
                                         product a row is has to be readable at a glance, but it is
                                         not worth a column of its own — the icon carries it on the
                                         scan, and the line under the reference says it in words
                                         alongside the route or the property. --}}
                                    <td class="px-5 py-3.5">
                                        <div class="flex items-center gap-3">
                                            <span @class([
                                                'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                                                'bg-teal-50 text-teal-700' => $isHotel,
                                                'bg-indigo-50 text-indigo-700' => ! $isHotel,
                                            ])>
                                                @if ($isHotel)
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" />
                                                    </svg>
                                                @else
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                                                    </svg>
                                                @endif
                                            </span>

                                            <div class="min-w-0">
                                                <div class="font-mono font-medium text-brand-900">{{ $booking->reference }}</div>
                                                <div class="mt-0.5 max-w-xs truncate text-xs text-gray-500">
                                                    <span @class([
                                                        'font-semibold',
                                                        'text-teal-700' => $isHotel,
                                                        'text-indigo-700' => ! $isHotel,
                                                    ])>{{ $booking->product->label() }}</span>
                                                    @if (filled($summary))
                                                        · {{ $summary }}
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset {{ $booking->status->badgeClasses() }}">{{ $booking->status->label() }}</span>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ring-1 ring-inset',
                                            'bg-red-50 text-red-700 ring-red-600/30' => $booking->environment === 'live',
                                            'bg-gray-50 text-gray-500 ring-gray-500/20' => $booking->environment !== 'live',
                                        ])>{{ $booking->environment }}</span>
                                    </td>
                                    <td class="px-5 py-3.5 text-gray-600">{{ count($booking->pax ?? []) }}</td>
                                    <td class="whitespace-nowrap px-5 py-3.5 font-medium text-brand-900">{{ $booking->currency }} {{ number_format((float) $booking->total_amount, 2) }}</td>
                                    <td class="whitespace-nowrap px-5 py-3.5 text-gray-500">{{ $booking->created_at?->format('M j, Y H:i') }}</td>
                                    <td class="px-5 py-3.5 text-right">
                                        <a href="{{ route('bookings.show', $booking) }}" class="text-xs font-medium text-blue-600 hover:text-blue-700">View</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($bookings->hasPages())
                    <div class="border-t border-gray-100 px-5 py-3">
                        {{ $bookings->links() }}
                    </div>
                @endif
            @endif
        </div>
    </div>
</x-app-layout>
