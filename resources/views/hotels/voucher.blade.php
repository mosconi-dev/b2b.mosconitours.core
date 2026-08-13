{{--
    The hotel voucher — the document a guest presents at the desk.

    Deliberately standalone rather than inside the app layout: it is printed, so it
    carries no navigation, and it renders from hotel_bookings alone so it works during
    a TBO outage and reads the same in two years' time.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Voucher {{ $stay->confirmation_number }} · {{ $stay->hotel_name }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: #fff; }
        }
    </style>
</head>
<body class="bg-gray-100 font-sans text-gray-900 antialiased">

    <div class="no-print mx-auto flex max-w-3xl items-center justify-between gap-4 px-6 pt-6">
        <a href="{{ route('bookings.show', $booking) }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to booking</a>
        <button type="button" onclick="window.print()"
                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
            Print voucher
        </button>
    </div>

    <div class="mx-auto my-6 max-w-3xl bg-white p-10 shadow-sm print:my-0 print:shadow-none">

        <div class="flex items-start justify-between gap-6 border-b border-gray-200 pb-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Hotel voucher</p>
                <h1 class="mt-1 text-2xl font-bold text-brand-900">{{ $stay->hotel_name }}</h1>
                @if (filled($stay->address))
                    <p class="mt-1 text-sm text-gray-600">{{ $stay->address }}</p>
                @endif
                @if ($stay->rating)
                    <p class="mt-1 text-sm text-amber-500">@for ($i = 0; $i < (int) $stay->rating; $i++)★@endfor</p>
                @endif
            </div>
            <div class="shrink-0 text-right">
                <p class="text-xs text-gray-500">Confirmation number</p>
                <p class="font-mono text-xl font-bold text-brand-900">{{ $stay->confirmation_number }}</p>
                @if (filled($stay->hotel_confirmation_number))
                    <p class="mt-2 text-xs text-gray-500">Hotel reference</p>
                    <p class="font-mono text-sm font-semibold text-brand-900">{{ $stay->hotel_confirmation_number }}</p>
                @endif
                @if ($booking->environment !== 'live')
                    <p class="mt-2 inline-block rounded bg-gray-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-gray-500">
                        {{ $booking->environment }} — not a real booking
                    </p>
                @endif
            </div>
        </div>

        <dl class="mt-6 grid grid-cols-2 gap-6 sm:grid-cols-4">
            <div>
                <dt class="text-xs text-gray-500">Check-in</dt>
                <dd class="mt-0.5 font-semibold text-brand-900">{{ $stay->check_in->format('D, j M Y') }}</dd>
                @if (filled($stay->checkin_time ?? null))
                    <dd class="text-xs text-gray-500">from {{ $stay->checkin_time }}</dd>
                @endif
            </div>
            <div>
                <dt class="text-xs text-gray-500">Check-out</dt>
                <dd class="mt-0.5 font-semibold text-brand-900">{{ $stay->check_out->format('D, j M Y') }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">Nights</dt>
                <dd class="mt-0.5 font-semibold text-brand-900">{{ $stay->nights }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">Rooms</dt>
                <dd class="mt-0.5 font-semibold text-brand-900">{{ $stay->rooms_count }}</dd>
            </div>
        </dl>

        {{-- Rooms and who is in them --}}
        <div class="mt-8 border-t border-gray-200 pt-6">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Guests</h2>

            @php
                $byRoom = collect($booking->pax ?? [])->groupBy('roomIndex');
                $names = $stay->room_names ?? [];
            @endphp

            <div class="mt-3 space-y-4">
                @foreach ($byRoom as $roomIndex => $guests)
                    <div>
                        <p class="text-sm font-medium text-brand-900">
                            Room {{ $roomIndex + 1 }}@if (! empty($names[$roomIndex])) · {{ $names[$roomIndex] }} @endif
                        </p>
                        <ul class="mt-1 text-sm text-gray-700">
                            @foreach ($guests as $guest)
                                <li>
                                    {{ $guest['title'] ?? '' }} {{ $guest['firstName'] ?? '' }} {{ $guest['lastName'] ?? '' }}
                                    <span class="text-xs text-gray-400">{{ $guest['type'] ?? '' }}</span>
                                    @if ($guest['isLead'] ?? false)
                                        <span class="text-xs font-medium text-brand-700">· lead guest</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>

            <p class="mt-3 text-sm text-gray-600">
                {{ $stay->meal_type === 'Room_Only' ? 'Room only' : str_replace('_', ' ', (string) $stay->meal_type) }}
                @if ($stay->with_transfers) · Transfers included @endif
            </p>
        </div>

        {{-- What the guest still owes. On the voucher because the desk will ask. --}}
        @if ($stay->payableAtProperty() !== [])
            <div class="mt-8 rounded-lg border border-amber-300 bg-amber-50 p-5">
                <h2 class="text-sm font-semibold text-amber-900">Payable at the hotel</h2>
                <p class="mt-0.5 text-xs text-amber-800">Not included in the amount paid to the agency.</p>
                <ul class="mt-2 space-y-1 text-sm text-amber-900">
                    @foreach ($stay->payableAtProperty() as $supplement)
                        <li class="flex justify-between gap-4">
                            <span>
                                {{ $supplement['description'] }}
                                @if ($supplement['count'] > 1)<span class="text-amber-800/70">× {{ $supplement['count'] }} rooms</span>@endif
                            </span>
                            <span class="font-medium">
                                {{ $supplement['currency'] ?: $booking->currency }} {{ number_format((float) $supplement['total'], 2) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Cancellation, from the PreBook policy §18 makes binding. --}}
        @php $schedule = data_get($booking->quote, 'cancellationSchedule', []); @endphp
        @if (! empty($schedule))
            <div class="mt-8 border-t border-gray-200 pt-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Cancellation</h2>
                <ul class="mt-2 space-y-1 text-sm text-gray-700">
                    @foreach ($schedule as $policy)
                        <li>
                            @if ($policy['room'] ?? null)<span class="text-gray-400">Room {{ $policy['room'] }} ·</span>@endif
                            From {{ \Illuminate\Support\Carbon::parse($policy['from'])->format('j M Y') }}:
                            <span class="font-medium">
                                @if ((float) $policy['charge'] <= 0)
                                    no charge
                                @elseif (($policy['chargeType'] ?? '') === 'Percentage')
                                    {{ rtrim(rtrim(number_format((float) $policy['charge'], 2), '0'), '.') }}% of the stay
                                @else
                                    {{ $booking->currency }} {{ number_format((float) $policy['charge'], 2) }}
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- The hotel's own norms, as returned by PreBook and made safe on the way in. --}}
        @if (! empty($stay->rate_conditions))
            <div class="mt-8 border-t border-gray-200 pt-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Hotel conditions</h2>
                <div class="mt-2 space-y-2 text-xs text-gray-600">
                    @foreach ($stay->rate_conditions as $condition)
                        <div class="supplier-prose">{!! $condition !!}</div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="mt-8 flex items-end justify-between gap-6 border-t border-gray-200 pt-6">
            <div class="text-xs text-gray-500">
                <p>Booking reference <span class="font-mono font-semibold text-brand-900">{{ $booking->reference }}</span></p>
                <p class="mt-0.5">Issued {{ $booking->created_at?->format('j M Y') }}</p>
                @if (filled($stay->invoice_number))
                    <p class="mt-0.5">Invoice {{ $stay->invoice_number }}</p>
                @endif
            </div>
            <div class="text-right">
                <p class="text-xs text-gray-500">Paid to the agency</p>
                <p class="text-lg font-bold text-brand-900">
                    {{ $booking->currency }} {{ number_format((float) $booking->total_amount, 2) }}
                </p>
            </div>
        </div>
    </div>
</body>
</html>
