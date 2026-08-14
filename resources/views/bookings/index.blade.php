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
                                {{-- One list, two vocabularies: a hotel has guests and a
                                     flight has passengers, so the word that covers both. --}}
                                <th class="px-5 py-3">Traveller</th>
                                <th class="px-5 py-3">Total</th>
                                <th class="px-5 py-3">Created</th>
                                <th class="px-5 py-3 text-right"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($bookings as $booking)
                                @include('bookings._row', ['booking' => $booking, 'withProduct' => true])
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
