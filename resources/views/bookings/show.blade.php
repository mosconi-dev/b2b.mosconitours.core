<x-app-layout>
    <x-slot name="header">
        <x-page-heading :title="$booking->reference" title-class="font-mono">
            <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ring-1 ring-inset {{ $booking->status->badgeClasses() }}">{{ $booking->status->label() }}</span>
            <span @class([
                'hidden shrink-0 items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ring-1 ring-inset sm:inline-flex',
                'bg-red-50 text-red-700 ring-red-600/30' => $booking->environment === 'live',
                'bg-gray-50 text-gray-500 ring-gray-500/20' => $booking->environment !== 'live',
            ])>{{ $booking->environment }}</span>
        </x-page-heading>
    </x-slot>

    <x-slot name="back">
        <a href="{{ route('bookings.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to bookings</a>
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
                @if ((float) $booking->ancillary_total > 0)
                    <div>
                        <dt class="text-gray-500">Add-ons</dt>
                        <dd class="font-medium text-brand-900">{{ $booking->currency }} {{ number_format((float) $booking->ancillary_total, 2) }}</dd>
                    </div>
                @endif
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

        {{-- Ticketing. A quoted booking is only a priced hold on our side; nothing
             exists at the airline until one of these is pressed. --}}
        @php
            // Non-LCC reserves first, then issues. LCC has no hold step — Ticket books
            // and issues together — so it goes straight to Issue from quoted.
            $canHold = ! $booking->is_lcc && $booking->status === \App\Enums\BookingStatus::Quoted;
            $canIssue = $booking->status === ($booking->is_lcc
                ? \App\Enums\BookingStatus::Quoted
                : \App\Enums\BookingStatus::Booked);
        @endphp

        @if ($canHold || $canIssue)
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-brand-900">Ticketing</h2>

                @if ($booking->environment === 'live')
                    <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        <strong>This is a LIVE booking.</strong> Issuing creates a real ticket and spends real money.
                    </div>
                @endif

                <p class="mt-3 text-sm text-gray-500">
                    @if ($canHold)
                        This fare is held on our side only. <strong class="text-brand-900">Hold PNR</strong> reserves
                        the seats with the airline without paying; you then issue the ticket separately.
                    @else
                        Issuing charges {{ $booking->currency }}
                        {{ number_format((float) $booking->total_amount, 2) }} and cannot be undone here.
                    @endif
                </p>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    @if ($canHold)
                        @can('flight.book')
                            <form method="POST" action="{{ route('bookings.book', $booking) }}">
                                @csrf
                                <button type="submit"
                                        class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50">
                                    Hold PNR
                                </button>
                            </form>
                        @else
                            <p class="text-sm text-gray-400">You do not have permission to hold this booking.</p>
                        @endcan
                    @endif

                    @if ($canIssue)
                        @can('flight.issue')
                            <form method="POST" action="{{ route('bookings.issue', $booking) }}"
                                  x-data="{ submitting: false }" @submit="submitting = true">
                                @csrf
                                <button type="submit" :disabled="submitting"
                                        @class([
                                            'rounded-lg px-5 py-2 text-sm font-semibold text-white shadow-sm transition disabled:opacity-50',
                                            'bg-red-600 hover:bg-red-700' => $booking->environment === 'live',
                                            'bg-blue-600 hover:bg-blue-700' => $booking->environment !== 'live',
                                        ])>
                                    <span x-show="! submitting">Issue ticket</span>
                                    <span x-show="submitting" x-cloak>Issuing…</span>
                                </button>
                            </form>
                        @else
                            <p class="text-sm text-gray-400">You do not have permission to issue tickets.</p>
                        @endcan
                    @endif
                </div>
            </div>
        @endif

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
                                if (! empty($p['ssr']['baggage'])) { $meta[] = 'Baggage '.$p['ssr']['baggage']['label']; }
                                if (! empty($p['ssr']['meal'])) { $meta[] = $p['ssr']['meal']['label']; }
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
                            <span class="text-brand-900">{{ $booking->currency }} {{ number_format((((float) ($b['baseFare'] ?? 0)) + ((float) ($b['tax'] ?? 0))) * ((int) ($b['count'] ?? 1)), 2) }}</span>
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
