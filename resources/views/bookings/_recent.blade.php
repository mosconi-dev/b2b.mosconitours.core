{{-- The agent's last few bookings for this product, above the search form.

     The same row as the bookings list, minus what a one-product page already answers:
     the environment rides next to the reference instead of holding a column of its
     own, and the column the list gives to a traveller count names the lead traveller
     instead — on four rows there is room to say who the booking is under.

     Expects: $bookings (Booking collection), $product (BookingProduct). --}}
@php
    $isHotel = $product === \App\Enums\BookingProduct::Hotel;
@endphp

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
        <h2 class="text-base font-semibold text-brand-900">Recent bookings</h2>
        <a href="{{ route('bookings.index', ['product' => $product->value]) }}"
           class="text-sm font-medium text-blue-600 transition hover:text-blue-700">View all →</a>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-100 text-sm">
            <thead>
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <th class="px-5 py-3">Booking</th>
                    <th class="px-5 py-3">Status</th>
                    <th class="px-5 py-3">{{ $isHotel ? 'Guest' : 'Passenger' }}</th>
                    <th class="px-5 py-3">Total</th>
                    <th class="px-5 py-3">Created</th>
                    <th class="px-5 py-3 text-right"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($bookings as $booking)
                    @php
                        // What was bought — the route for a flight, the property for a hotel.
                        $summary = $booking->itinerarySummary();
                        $lead = $booking->leadPassengerName();
                        $others = max(0, count($booking->pax ?? []) - 1);
                    @endphp
                    <tr class="cursor-pointer transition hover:bg-gray-50" onclick="window.location='{{ route('bookings.show', $booking) }}'">
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
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono font-medium text-brand-900">{{ $booking->reference }}</span>
                                        {{-- Which supplier environment made this booking. It belongs beside
                                             the reference rather than in a column of its own: it is a fact
                                             about the reference, and it is the one thing that says whether
                                             the row is real money. --}}
                                        <span @class([
                                            'inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ring-1 ring-inset',
                                            'bg-red-50 text-red-700 ring-red-600/30' => $booking->environment === 'live',
                                            'bg-gray-50 text-gray-500 ring-gray-500/20' => $booking->environment !== 'live',
                                        ])>{{ $booking->environment }}</span>
                                    </div>
                                    @if (filled($summary))
                                        <div class="mt-0.5 max-w-xs truncate text-xs text-gray-500">{{ $summary }}</div>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset {{ $booking->status->badgeClasses() }}">{{ $booking->status->label() }}</span>
                        </td>
                        <td class="px-5 py-3.5">
                            <div class="text-gray-700">{{ $lead ?? '—' }}</div>
                            @if ($others > 0)
                                <div class="text-xs text-gray-400">+{{ $others }} more</div>
                            @endif
                        </td>
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
</div>
