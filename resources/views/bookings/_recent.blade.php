{{-- The agent's last few bookings for this product, above the search form.

     The same row as the bookings list — one file, see bookings/_row — under a header
     that names the product's own vocabulary for the people travelling.

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
                    @include('bookings._row', ['booking' => $booking, 'withProduct' => false])
                @endforeach
            </tbody>
        </table>
    </div>
</div>
