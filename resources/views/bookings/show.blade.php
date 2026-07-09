<x-app-layout>
    <x-slot name="header">
        <div>
            <a href="{{ route('bookings.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to bookings</a>
            <div class="mt-1 flex items-center gap-3">
                <h1 class="font-mono text-2xl font-bold tracking-tight text-brand-900">{{ $booking->reference }}</h1>
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ring-1 ring-inset {{ $booking->status->badgeClasses() }}">{{ $booking->status->label() }}</span>
                <span @class([
                    'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ring-1 ring-inset',
                    'bg-red-50 text-red-700 ring-red-600/30' => $booking->environment === 'live',
                    'bg-gray-50 text-gray-500 ring-gray-500/20' => $booking->environment !== 'live',
                ])>{{ $booking->environment }}</span>
            </div>
        </div>
    </x-slot>

    <div class="max-w-3xl space-y-6">
        <x-admin.flash />

        {{-- Summary --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-brand-900">Summary</h2>
            <dl class="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-gray-500">Total</dt>
                    <dd class="font-semibold text-brand-900">{{ $booking->currency }} {{ number_format((float) $booking->total_amount, 2) }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Fare type</dt>
                    <dd class="font-medium text-brand-900">{{ $booking->is_lcc ? 'Low-cost (LCC)' : 'GDS' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">PNR</dt>
                    <dd class="font-medium text-brand-900">{{ $booking->pnr ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Created</dt>
                    <dd class="font-medium text-brand-900">{{ $booking->created_at?->format('M j, Y H:i') }}</dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-gray-500">Trace</dt>
                    <dd class="truncate font-mono text-xs text-gray-500">{{ $booking->trace_id ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        {{-- Passengers --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-brand-900">Passengers</h2>
            <div class="mt-4 divide-y divide-gray-100">
                @foreach ($booking->pax ?? [] as $p)
                    <div class="flex items-center justify-between py-2.5 text-sm">
                        <div>
                            <p class="font-medium text-brand-900">{{ $p['title'] ?? '' }} {{ $p['firstName'] ?? '' }} {{ $p['lastName'] ?? '' }}</p>
                            @php
                                $meta = [$p['type'] ?? 'Passenger'];
                                if (! empty($p['dateOfBirth'])) { $meta[] = $p['dateOfBirth']; }
                                if (! empty($p['passportNo'])) { $meta[] = 'Passport '.$p['passportNo']; }
                            @endphp
                            <p class="text-xs text-gray-500">{{ implode(' · ', $meta) }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Fare breakdown --}}
        @php
            $breakdown = data_get($booking->quote, 'fareBreakdown', []);
        @endphp
        @if (! empty($breakdown))
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-brand-900">Fare breakdown</h2>
                <div class="mt-4 divide-y divide-gray-100 text-sm">
                    @foreach ($breakdown as $b)
                        <div class="flex items-center justify-between py-2">
                            <span class="text-gray-600">{{ $b['count'] ?? 1 }} × {{ $b['passengerType'] ?? 'Passenger' }}</span>
                            <span class="text-brand-900">{{ $booking->currency }} {{ number_format((((float) ($b['baseFare'] ?? 0)) + ((float) ($b['tax'] ?? 0))) * ((int) ($b['count'] ?? 1))) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Contact --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-brand-900">Contact</h2>
            <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-gray-500">Email</dt>
                    <dd class="font-medium text-brand-900">{{ data_get($booking->contact, 'email', '—') }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Phone</dt>
                    <dd class="font-medium text-brand-900">{{ data_get($booking->contact, 'phone', '—') }}</dd>
                </div>
            </dl>
        </div>

        <p class="text-xs text-gray-400">Ticketing (Book / Ticket) is not enabled yet — this booking is a saved, priced quote.</p>
    </div>
</x-app-layout>
