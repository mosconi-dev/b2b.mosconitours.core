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
        <div class="flex items-center justify-between gap-4">
            <a href="{{ route('bookings.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to bookings</a>

            {{-- Nothing to print until the airline has given us a PNR. --}}
            @if (filled($booking->pnr))
                <a href="{{ route('bookings.eticket', $booking) }}" target="_blank"
                   class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5z" />
                    </svg>
                    {{ $booking->pax && collect($booking->pax)->contains(fn ($p) => filled($p['ticketNumber'] ?? null)) ? 'Print e-ticket' : 'Print confirmation' }}
                </a>
            @endif
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
                @if ((float) $booking->ancillary_total > 0)
                    <div>
                        <dt class="text-gray-500">Add-ons</dt>
                        <dd class="font-medium text-brand-900">{{ $booking->currency }} {{ number_format((float) $booking->ancillary_total, 2) }}</dd>
                    </div>
                @endif
                @unless ($booking->isHotel())
                    <div>
                        <dt class="text-gray-500">Fare type</dt>
                        <dd class="font-medium text-brand-900">{{ $booking->is_lcc ? 'Low-cost (LCC)' : 'GDS' }}</dd>
                    </div>
                @endunless
                {{-- Both products have a supplier reference; only flights call it a PNR
                     and only flights have a trace. BookingProduct::referenceLabel()
                     carries the naming so it is decided in one place. --}}
                <div>
                    <dt class="text-gray-500">{{ $booking->product->referenceLabel() }}</dt>
                    <dd class="font-medium text-brand-900">{{ $booking->supplier_reference ?? $booking->pnr ?? '—' }}</dd>
                </div>
                @if (filled($booking->booking_id))
                    <div>
                        <dt class="text-gray-500">Airline booking ID</dt>
                        <dd class="font-medium text-brand-900">{{ $booking->booking_id }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-gray-500">Created</dt>
                    <dd class="font-medium text-brand-900">{{ $booking->created_at?->format('M j, Y H:i') }}</dd>
                </div>
                @unless ($booking->isHotel())
                    <div class="col-span-2">
                        <dt class="text-gray-500">Trace</dt>
                        <dd class="truncate font-mono text-xs text-gray-500">{{ $booking->trace_id ?? '—' }}</dd>
                    </div>
                @endunless
            </dl>
        </div>

        {{-- Ticketing. There is no hold to press: completing the booking queued Book →
             Ticket as one act, so this panel reports on that rather than driving it.
             The only button here is a recovery, for the one state that strands. --}}
        @php
            $statuses = \App\Enums\BookingStatus::class;
            $canRetry = auth()->user()->can('flight.book') && auth()->user()->can('flight.issue');
        @endphp

        @if ($booking->status === $statuses::Processing)
            {{-- Follows the queue to its ending. Book and Ticket together have taken
                 over a minute against the real supplier, so this can sit a while. --}}
            <div class="rounded-xl border border-sky-200 bg-sky-50 p-6 shadow-sm"
                 x-data="{
                     poll() {
                         fetch('{{ route('bookings.status', $booking) }}', { headers: { Accept: 'application/json' } })
                             .then(r => r.ok ? r.json() : null)
                             .then(d => d && !d.inFlight ? window.location.reload() : setTimeout(() => this.poll(), 4000))
                             .catch(() => setTimeout(() => this.poll(), 8000));
                     },
                 }"
                 x-init="setTimeout(() => poll(), 4000)">
                <div class="flex items-start gap-3">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 animate-spin text-sky-600" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    <div>
                        <h2 class="text-base font-semibold text-sky-900">Contacting the airline</h2>
                        <p class="mt-1 text-sm text-sky-800">
                            {{ $booking->is_lcc ? 'Issuing your ticket' : 'Reserving your seats, then issuing the ticket' }}.
                            This can take a minute or two — the page updates itself when it is done, and it keeps
                            going if you leave.
                        </p>
                    </div>
                </div>
            </div>
        @elseif ($booking->status === $statuses::Booked)
            {{-- The one genuinely stranded state: seats reserved, nothing paid for. It
                 must be finished or it eventually dies at the airline. --}}
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-6 shadow-sm">
                <h2 class="text-base font-semibold text-amber-900">Ticket not issued</h2>
                <p class="mt-2 text-sm text-amber-800">
                    The airline reserved these seats under PNR <strong>{{ $booking->pnr }}</strong>, but the ticket
                    was not issued. The reservation will be released by the airline if it stays unticketed.
                </p>
                @if ($canRetry)
                    <form method="POST" action="{{ route('bookings.fulfil', $booking) }}" class="mt-4"
                          x-data="{ submitting: false }" @submit="submitting = true">
                        @csrf
                        <button type="submit" :disabled="submitting"
                                class="rounded-lg bg-amber-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-amber-700 disabled:opacity-50">
                            <span x-show="! submitting">Finish ticketing</span>
                            <span x-show="submitting" x-cloak>Sending…</span>
                        </button>
                    </form>
                @else
                    <p class="mt-3 text-sm text-amber-700">You do not have permission to issue tickets — ask someone who does.</p>
                @endif
            </div>
        @elseif ($booking->status === $statuses::Failed)
            <div class="rounded-xl border border-red-200 bg-red-50 p-6 shadow-sm">
                <h2 class="text-base font-semibold text-red-900">This booking did not complete</h2>
                <p class="mt-2 text-sm text-red-800">
                    The airline did not issue a ticket
                    @if (filled($booking->pnr))
                        against PNR <strong>{{ $booking->pnr }}</strong> — check with support before rebooking, so the
                        same passengers are not ticketed twice.
                    @else
                        and nothing was reserved. Search again to rebook these passengers.
                    @endif
                </p>
            </div>
        @elseif ($booking->status === $statuses::Quoted && $booking->isHotel())
            {{-- A hotel quote is finished on our side and not yet sent to TBO. There is
                 no ticket to issue and no airline to call, so the flight panel below
                 must not be offered here — its button runs Book → Ticket against TBO
                 Air. Vouchering arrives in the next phase. --}}
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-brand-900">Not yet confirmed</h2>
                <p class="mt-2 text-sm text-gray-500">
                    This stay is saved and paid for on our side. Nothing has been sent to the hotel yet.
                </p>
            </div>
        @elseif ($booking->status === $statuses::Quoted)
            {{-- Only reachable for bookings saved before ticketing became one act. --}}
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-brand-900">Not yet ticketed</h2>
                <p class="mt-2 text-sm text-gray-500">
                    This is a saved quote — nothing has been sent to the airline. Completing it charges
                    {{ $booking->currency }} {{ number_format((float) $booking->total_amount, 2) }} and issues the ticket.
                </p>
                @if ($booking->environment === 'live')
                    <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        <strong>This is a LIVE booking.</strong> Completing it creates a real ticket and spends real money.
                    </div>
                @endif
                @if ($canRetry)
                    <form method="POST" action="{{ route('bookings.fulfil', $booking) }}" class="mt-4"
                          x-data="{ submitting: false }" @submit="submitting = true">
                        @csrf
                        <button type="submit" :disabled="submitting"
                                @class([
                                    'rounded-lg px-5 py-2 text-sm font-semibold text-white shadow-sm transition disabled:opacity-50',
                                    'bg-red-600 hover:bg-red-700' => $booking->environment === 'live',
                                    'bg-blue-600 hover:bg-blue-700' => $booking->environment !== 'live',
                                ])>
                            <span x-show="! submitting">Complete booking</span>
                            <span x-show="submitting" x-cloak>Sending…</span>
                        </button>
                    </form>
                @else
                    <p class="mt-3 text-sm text-gray-400">You do not have permission to issue tickets.</p>
                @endif
            </div>
        @endif

        {{-- The stay. Read entirely from hotel_bookings, never from the catalogue: a
             booking must read the same years later, whatever has happened to the
             property since. --}}
        @if ($booking->isHotel() && $booking->hotel)
            @php $stay = $booking->hotel; @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-brand-900">{{ $stay->hotel_name }}</h2>
                @if (filled($stay->address))
                    <p class="mt-0.5 text-sm text-gray-500">{{ $stay->address }}</p>
                @endif

                <dl class="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-gray-500">Check-in</dt>
                        <dd class="font-medium text-brand-900">{{ $stay->check_in->format('j M Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Check-out</dt>
                        <dd class="font-medium text-brand-900">{{ $stay->check_out->format('j M Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Nights</dt>
                        <dd class="font-medium text-brand-900">{{ $stay->nights }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Rooms</dt>
                        <dd class="font-medium text-brand-900">{{ $stay->rooms_count }}</dd>
                    </div>
                </dl>

                @if (! empty($stay->room_names))
                    <ul class="mt-4 space-y-1 border-t border-gray-100 pt-4 text-sm text-gray-600">
                        @foreach ($stay->room_names as $name)
                            <li>{{ $name }}</li>
                        @endforeach
                    </ul>
                @endif

                <div class="mt-4 flex flex-wrap gap-1.5">
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                        {{ $stay->is_refundable ? 'Refundable' : 'Non-refundable' }}
                    </span>
                    @if ($stay->with_transfers)
                        <span class="rounded-full bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-700">Transfers</span>
                    @endif
                    @if (filled($stay->confirmation_number))
                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">
                            Confirmation {{ $stay->confirmation_number }}
                        </span>
                    @endif
                </div>

                {{-- Shown here as well as before booking: the guest still has to pay it,
                     and the agent may be reading this page to answer that question. --}}
                @if ($stay->payableAtProperty() !== [])
                    <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50/60 p-4">
                        <p class="text-sm font-semibold text-amber-900">Payable at the hotel</p>
                        <ul class="mt-2 space-y-1 text-sm text-amber-900">
                            @foreach ($stay->payableAtProperty() as $supplement)
                                <li class="flex justify-between gap-4">
                                    <span>{{ $supplement['description'] ?? 'Additional charge' }}</span>
                                    <span class="font-medium">
                                        {{ $supplement['currency'] ?? $booking->currency }}
                                        {{ number_format((float) ($supplement['price'] ?? 0), 2) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        {{-- Itinerary. The booking page showed no flight at all before this — every
             field here already sat in the stored quote. --}}
        @php $trips = data_get($booking->quote, 'trips', []); @endphp
        @if (! empty($trips))
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-brand-900">Itinerary</h2>

                @foreach ($trips as $trip)
                    <div class="mt-4 @if(! $loop->first) border-t border-gray-100 pt-4 @endif">
                        @if (count($trips) > 1)
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                {{ $trip['direction'] === 'inbound' ? 'Return' : 'Outbound' }}
                            </p>
                        @endif

                        @foreach ($trip['segments'] ?? [] as $seg)
                            @php
                                $dep = data_get($seg, 'origin.time');
                                $arr = data_get($seg, 'destination.time');
                                $fmt = fn (?string $t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('M j, g:i A') : '—';
                            @endphp
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-brand-900">
                                        {{ data_get($seg, 'origin.code') }} → {{ data_get($seg, 'destination.code') }}
                                        <span class="ml-1 font-normal text-gray-400">{{ $seg['flightNumber'] ?? '' }}</span>
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        {{ $fmt($dep) }} — {{ $fmt($arr) }}
                                        @if (! empty($seg['duration'])) · {{ intdiv((int) $seg['duration'], 60) }}h {{ (int) $seg['duration'] % 60 }}m @endif
                                    </p>
                                </div>
                                <p class="text-xs text-gray-500">
                                    {{ $seg['airlineName'] ?? '' }}
                                    @if (! empty($seg['cabin'])) · {{ $seg['cabin'] }} @endif
                                    @if (! empty($seg['baggage'])) · {{ $seg['baggage'] }} checked @endif
                                </p>
                            </div>

                            @if (! empty($seg['layoverAfter']))
                                <p class="border-l-2 border-gray-100 py-1 pl-3 text-xs text-gray-400">
                                    {{ intdiv((int) $seg['layoverAfter'], 60) }}h {{ (int) $seg['layoverAfter'] % 60 }}m layover
                                </p>
                            @endif
                        @endforeach
                    </div>
                @endforeach

                @php $rules = data_get($booking->quote, 'miniFareRules', []); @endphp
                @if (! empty($rules))
                    <details class="mt-4 border-t border-gray-100 pt-3">
                        <summary class="cursor-pointer text-xs font-medium text-gray-500 hover:text-gray-700">Fare conditions</summary>
                        <div class="mt-2 space-y-1">
                            @foreach ($rules as $rule)
                                <p class="text-xs text-gray-500">
                                    <span class="font-medium text-gray-700">{{ $rule['type'] ?? '' }}</span>
                                    {{ $rule['details'] ?? '' }}
                                    @if (! empty($rule['journeyPoints'])) <span class="text-gray-400">({{ $rule['journeyPoints'] }})</span> @endif
                                </p>
                            @endforeach
                        </div>
                    </details>
                @endif
            </div>
        @endif

        {{-- Passengers --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-brand-900">{{ $booking->isHotel() ? 'Guests' : 'Passengers' }}</h2>
            <div class="mt-4 divide-y divide-gray-100">
                @foreach ($booking->pax ?? [] as $p)
                    <div class="flex items-center justify-between py-2.5 text-sm">
                        <div>
                            <p class="font-medium text-brand-900">{{ $p['title'] ?? '' }} {{ $p['firstName'] ?? '' }} {{ $p['lastName'] ?? '' }}</p>
                            @php
                                $meta = [$p['type'] ?? 'Passenger'];
                                if (! empty($p['dateOfBirth'])) { $meta[] = $p['dateOfBirth']; }
                                if (! empty($p['documentNumber'])) {
                                    $meta[] = (data_get($booking->quote, 'isDomestic') ? 'ID ' : 'Passport ').$p['documentNumber'];
                                }
                                // Per leg, so a list — with the single-option shape from
                                // before that change still readable.
                                foreach (['baggage' => 'Baggage ', 'meal' => ''] as $kind => $prefix) {
                                    $items = $p['ssr'][$kind] ?? [];
                                    if (filled($items['code'] ?? null)) { $items = [$items]; }
                                    foreach ((array) $items as $item) {
                                        if (filled($item['label'] ?? null)) {
                                            $meta[] = $prefix.$item['label']
                                                .(filled($item['origin'] ?? null) ? " ({$item['origin']}→{$item['destination']})" : '');
                                        }
                                    }
                                }
                            @endphp
                            <p class="text-xs text-gray-500">{{ implode(' · ', $meta) }}</p>
                        </div>

                        @if (! empty($p['ticketNumber']))
                            <div class="shrink-0 text-right">
                                <p class="font-mono text-sm font-medium text-emerald-700">{{ $p['ticketNumber'] }}</p>
                                <p class="text-[11px] text-gray-400">
                                    Ticket
                                    @if (! empty($p['ticketIssuedAt']))
                                        · {{ \Illuminate\Support\Carbon::parse($p['ticketIssuedAt'])->format('M j, Y') }}
                                    @endif
                                </p>
                            </div>
                        @endif
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
                            @php
                                // TBO's FareBreakdown entry already covers every passenger of
                                // that type — BaseFare and Tax are the combined figures, not
                                // per-head. Multiplying by count again doubled the line.
                                $line = ((float) ($b['baseFare'] ?? 0)) + ((float) ($b['tax'] ?? 0));
                                $count = max((int) ($b['count'] ?? 1), 1);
                            @endphp
                            <span class="text-gray-600">
                                {{ $count }} × {{ $b['passengerType'] ?? 'Passenger' }}
                                <span class="text-gray-400">· {{ $booking->currency }} {{ number_format($line / $count, 2) }} each</span>
                            </span>
                            <span class="text-brand-900">{{ $booking->currency }} {{ number_format($line, 2) }}</span>
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
                    <dd class="font-medium text-brand-900">
                        @if (filled(data_get($booking->contact, 'mobileCountryCode')))+{{ data_get($booking->contact, 'mobileCountryCode') }} @endif
                        {{ data_get($booking->contact, 'phone', '—') }}
                    </dd>
                </div>
                @if (filled(data_get($booking->contact, 'addressLine1')))
                    <div class="col-span-2">
                        <dt class="text-gray-500">Billing address</dt>
                        <dd class="font-medium text-brand-900">
                            {{ collect([
                                data_get($booking->contact, 'addressLine1'),
                                data_get($booking->contact, 'addressLine2'),
                                data_get($booking->contact, 'city'),
                                data_get($booking->contact, 'countryCode'),
                            ])->filter()->implode(', ') }}
                        </dd>
                    </div>
                @endif
            </dl>
        </div>

    </div>
</x-app-layout>
