<x-app-layout>
    <x-slot name="header">
        <div>
            <h1 class="flex items-center gap-2.5 text-2xl font-bold tracking-tight text-brand-900">
                <svg class="h-7 w-7 text-brand-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 010 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 010-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375z" />
                </svg>
                Bookings
            </h1>
            <p class="mt-1 text-sm text-gray-500">Your flight bookings.</p>
        </div>
    </x-slot>

    <div class="space-y-6">
        <x-admin.flash />

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            @if ($bookings->isEmpty())
                <div class="p-12 text-center">
                    <p class="text-sm font-medium text-brand-900">No bookings yet</p>
                    <p class="mt-1 text-sm text-gray-500">Search a flight, select a fare and confirm it to create a booking.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-5 py-3">Reference</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3">Env</th>
                                <th class="px-5 py-3">Passengers</th>
                                <th class="px-5 py-3">Total</th>
                                <th class="px-5 py-3">Created</th>
                                <th class="px-5 py-3 text-right"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($bookings as $booking)
                                <tr class="cursor-pointer transition hover:bg-gray-50" onclick="window.location='{{ route('bookings.show', $booking) }}'">
                                    <td class="whitespace-nowrap px-5 py-3.5 font-mono font-medium text-brand-900">{{ $booking->reference }}</td>
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
                                    <td class="whitespace-nowrap px-5 py-3.5 font-medium text-brand-900">{{ $booking->currency }} {{ number_format((float) $booking->total_amount) }}</td>
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
