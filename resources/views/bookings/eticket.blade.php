{{--
    The printable e-ticket / booking confirmation.

    Standalone: no app layout, no Tailwind, no JavaScript. The CSS is deliberately
    table-based and CSS 2.1 only (no flex, no grid, no custom properties) so this same
    template can be handed to dompdf for a downloadable PDF without being rewritten.
--}}
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $ticket->title() }} — {{ $booking->reference }}</title>
    <style>
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 12px;
            color: #111827;
            margin: 24px;
        }

        h1 { font-size: 22px; margin: 0; }
        h2 { font-size: 13px; margin: 18px 0 6px; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; }
        p  { margin: 0 0 4px; }

        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; text-align: left; padding: 0; }

        table.grid td, table.grid th {
            border: 1px solid #d1d5db;
            padding: 6px 8px;
        }
        table.grid th { background: #f3f4f6; font-weight: bold; }
        table.grid th.route { background: #e5e7eb; font-size: 12px; }

        .muted   { color: #6b7280; }
        .small   { font-size: 11px; }
        .bold    { font-weight: bold; }
        .mono    { font-family: "DejaVu Sans Mono", Consolas, monospace; }
        .right   { text-align: right; }
        .center  { text-align: center; }
        .nowrap  { white-space: nowrap; }

        .rule    { border: none; border-top: 1px solid #d1d5db; margin: 12px 0; }
        .notice  { border: 1px solid #fcd34d; background: #fffbeb; padding: 8px 10px; margin: 10px 0; }
        .layover { background: #f9fafb; font-style: italic; color: #6b7280; }
        .logo    { max-height: 56px; }

        .actions { margin-bottom: 16px; }
        .actions a, .actions button {
            font: inherit; color: #1d4ed8; background: none; border: none;
            padding: 0; margin-right: 14px; cursor: pointer; text-decoration: underline;
        }

        @media print {
            body { margin: 0; }
            .actions { display: none; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; }
        }
    </style>
</head>

<body>

<div class="actions">
    <button type="button" onclick="window.print()">Print</button>
    <a href="{{ route('bookings.show', $booking) }}">Back to booking</a>
    @if ($ticket->withPrices)
        <a href="{{ route('bookings.eticket', [$booking, 'prices' => 0]) }}">Passenger copy (hide fares)</a>
    @else
        <a href="{{ route('bookings.eticket', $booking) }}">Show fares</a>
    @endif
</div>

{{-- Header: who issued this, and what it is --}}
<table>
    <tr>
        @if ($booking->agency?->logo_path)
            <td style="width: 90px;">
                <img class="logo" src="{{ asset('storage/'.$booking->agency->logo_path) }}" alt="">
            </td>
        @endif
        <td>
            <p class="bold">{{ $booking->agency?->name }}</p>
            @if (filled($booking->agency?->address))
                <p class="small muted">{{ $booking->agency->address }}</p>
            @endif
            @if (filled($booking->agency?->contact_email))
                <p class="small muted">{{ $booking->agency->contact_email }}</p>
            @endif
            @if (filled($booking->agency?->contact_phone))
                <p class="small muted">{{ $booking->agency->contact_phone }}</p>
            @endif
        </td>
        <td class="right">
            <h1>{{ $ticket->title() }}</h1>
            @if ($booking->environment === 'live')
                <p class="small muted">Issued {{ now()->format('M j, Y') }}</p>
            @else
                <p class="small bold" style="color: #b45309;">TEST ENVIRONMENT — NOT VALID FOR TRAVEL</p>
            @endif
        </td>
    </tr>
</table>

<hr class="rule">

@if ($notice = $ticket->notice())
    <div class="notice">{{ $notice }}</div>
@endif

<table class="grid">
    <tr>
        <th>Booking reference</th>
        <th>Airline PNR</th>
        <th>Airline booking ID</th>
        <th>Status</th>
        <th>Booked</th>
    </tr>
    <tr>
        <td class="mono bold">{{ $booking->reference }}</td>
        <td class="mono bold">{{ $booking->pnr ?? '—' }}</td>
        <td class="mono">{{ $booking->booking_id ?? '—' }}</td>
        <td>{{ $booking->status->label() }}</td>
        <td class="nowrap">{{ $booking->created_at?->format('M j, Y g:i A') }}</td>
    </tr>
</table>

{{-- Flights --}}
<h2>Flight details</h2>
<table class="grid">
    @foreach ($ticket->trips() as $trip)
        <tr>
            <th class="route" colspan="5">
                @if ($trip['label']){{ $trip['label'] }} — @endif{{ $trip['route'] }}
            </th>
        </tr>
        <tr>
            <th style="width: 22%;">Airline</th>
            <th style="width: 24%;">Departure</th>
            <th style="width: 24%;">Arrival</th>
            <th style="width: 12%;">Duration</th>
            <th style="width: 18%;">Baggage</th>
        </tr>
        @foreach ($trip['segments'] as $segment)
            <tr>
                <td>
                    <span class="bold">{{ $segment['airlineName'] }}</span><br>
                    <span class="mono">{{ $segment['flightNumber'] }}</span>
                    @if ($segment['cabin']) <span class="muted">· {{ $segment['cabin'] }}</span>@endif
                    @if ($segment['fareClass']) <span class="muted small">({{ $segment['fareClass'] }})</span>@endif
                    @if ($segment['operatedBy'])
                        <br><span class="small muted">Operated by {{ $segment['operatedBy'] }}</span>
                    @endif
                    @if ($segment['aircraft'])
                        <br><span class="small muted">Aircraft {{ $segment['aircraft'] }}</span>
                    @endif
                </td>
                <td>
                    <span class="bold">{{ $segment['origin']['city'] }} ({{ $segment['origin']['code'] }})</span><br>
                    <span class="small muted">
                        {{ $segment['origin']['airport'] }}@if ($segment['origin']['terminal']) — Terminal {{ $segment['origin']['terminal'] }}@endif
                    </span><br>
                    {{ $segment['origin']['at']?->format('D, M j, Y g:i A') ?? '—' }}
                </td>
                <td>
                    <span class="bold">{{ $segment['destination']['city'] }} ({{ $segment['destination']['code'] }})</span><br>
                    <span class="small muted">
                        {{ $segment['destination']['airport'] }}@if ($segment['destination']['terminal']) — Terminal {{ $segment['destination']['terminal'] }}@endif
                    </span><br>
                    {{ $segment['destination']['at']?->format('D, M j, Y g:i A') ?? '—' }}
                </td>
                <td>{{ $segment['duration'] ?? '—' }}</td>
                <td class="small">
                    Checked: {{ $segment['baggage'] ?? 'not included' }}<br>
                    Cabin: {{ $segment['cabinBaggage'] ?? 'not included' }}
                </td>
            </tr>
            @if ($segment['layoverAfter'])
                <tr class="layover">
                    <td colspan="5" class="center">{{ $segment['layoverAfter'] }} layover in {{ $segment['destination']['city'] }}</td>
                </tr>
            @endif
        @endforeach
    @endforeach
</table>

{{-- Passengers --}}
<h2>Passengers</h2>
<table class="grid">
    <tr>
        <th style="width: 4%;">#</th>
        <th style="width: 32%;">Name</th>
        <th style="width: 12%;">Type</th>
        <th style="width: 26%;">Travel document</th>
        <th style="width: 26%;">e-Ticket number</th>
    </tr>
    @foreach ($ticket->passengers() as $i => $passenger)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>
                <span class="bold">{{ strtoupper($passenger['name']) }}</span>
                @if ($passenger['dateOfBirth'])
                    <br><span class="small muted">DOB {{ $passenger['dateOfBirth'] }}</span>
                @endif
            </td>
            <td>{{ $passenger['type'] }}</td>
            <td class="small">
                @if ($passenger['documentNumber'])
                    {{ $passenger['documentLabel'] }} <span class="mono">{{ $passenger['documentNumber'] }}</span>
                    @if ($passenger['documentExpiry'])
                        <br><span class="muted">Expires {{ $passenger['documentExpiry'] }}</span>
                    @endif
                    @if ($passenger['nationality'])
                        <br><span class="muted">Nationality {{ $passenger['nationality'] }}</span>
                    @endif
                @else
                    <span class="muted">—</span>
                @endif
            </td>
            <td>
                @if ($passenger['ticketNumber'])
                    <span class="mono bold">{{ $passenger['ticketNumber'] }}</span>
                    @if ($passenger['ticketIssuedAt'])
                        <br><span class="small muted">Issued {{ $passenger['ticketIssuedAt']->format('M j, Y') }}</span>
                    @endif
                @else
                    <span class="muted">Not issued</span>
                @endif
            </td>
        </tr>
    @endforeach
</table>

{{-- Contact --}}
<h2>Contact</h2>
<table class="grid">
    <tr>
        <th style="width: 25%;">Lead passenger</th>
        <td style="width: 25%;">{{ $ticket->contact()['name'] ?: '—' }}</td>
        <th style="width: 25%;">Phone</th>
        <td style="width: 25%;">{{ $ticket->contact()['phone'] ?: '—' }}</td>
    </tr>
    <tr>
        <th>E-mail</th>
        <td>{{ $ticket->contact()['email'] ?: '—' }}</td>
        <th>Address</th>
        <td>{{ $ticket->contact()['address'] ?: '—' }}</td>
    </tr>
</table>

{{-- Add-ons --}}
@if ($addOns = $ticket->addOns())
    <h2>Special service requests</h2>
    <table class="grid">
        <tr>
            <th>Passenger</th>
            <th>Type</th>
            <th>Description</th>
            <th>Route</th>
            @if ($ticket->withPrices)<th class="right">Price</th>@endif
        </tr>
        @foreach ($addOns as $addOn)
            <tr>
                <td>{{ strtoupper($addOn['passenger']) }}</td>
                <td>{{ $addOn['type'] }}</td>
                <td>{{ $addOn['description'] }}</td>
                <td>{{ $addOn['route'] ?: '—' }}</td>
                @if ($ticket->withPrices)
                    <td class="right nowrap">{{ $addOn['currency'] }} {{ number_format($addOn['price'], 2) }}</td>
                @endif
            </tr>
        @endforeach
    </table>
@endif

{{-- Fare. Hidden on the passenger copy: the agency's cost is not the traveller's business. --}}
@if ($ticket->withPrices)
    <h2>Fare</h2>
    <table class="grid">
        @foreach ($ticket->fareLines() as $line)
            <tr>
                <th colspan="2">{{ $line['label'] }} fare (× {{ $line['count'] }})</th>
            </tr>
            <tr>
                <td>Base fare <span class="muted small">· {{ $booking->currency }} {{ number_format($line['baseFare'] / $line['count'], 2) }} each</span></td>
                <td class="right nowrap">{{ $booking->currency }} {{ number_format($line['baseFare'], 2) }}</td>
            </tr>
            <tr>
                <td>Taxes and fees</td>
                <td class="right nowrap">{{ $booking->currency }} {{ number_format($line['tax'], 2) }}</td>
            </tr>
            <tr>
                <td class="bold right">{{ $line['label'] }} subtotal</td>
                <td class="bold right nowrap">{{ $booking->currency }} {{ number_format($line['total'], 2) }}</td>
            </tr>
        @endforeach

        @if ($ticket->addOnTotal() > 0)
            <tr>
                <td class="bold right">Add-ons</td>
                <td class="bold right nowrap">{{ $booking->currency }} {{ number_format($ticket->addOnTotal(), 2) }}</td>
            </tr>
        @endif

        {{-- Only when the lines above do not already sum to the total. --}}
        @if (abs($ticket->otherCharges()) >= 0.01)
            <tr>
                <td class="right">Other charges</td>
                <td class="right nowrap">{{ $booking->currency }} {{ number_format($ticket->otherCharges(), 2) }}</td>
            </tr>
        @endif

        <tr>
            <th class="right">Total paid</th>
            <th class="right nowrap">{{ $booking->currency }} {{ number_format($ticket->total(), 2) }}</th>
        </tr>
    </table>
@endif

{{-- Fare conditions, in the airline's own words as TBO supplied them --}}
@if ($rules = $ticket->fareRules())
    <h2>Fare conditions</h2>
    <table class="grid">
        @foreach ($rules as $rule)
            <tr>
                <th style="width: 25%;">{{ $rule['type'] ?? '' }}</th>
                <td>
                    {{ $rule['details'] ?? '' }}
                    @if (! empty($rule['journeyPoints']))
                        <span class="muted small">({{ $rule['journeyPoints'] }})</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </table>
@endif

<hr class="rule">
<p class="small muted">
    Please arrive at the airport in good time and carry the travel document listed above — it must be
    the one presented at check-in. Baggage allowances, changes and refunds are governed by the fare
    conditions and the operating airline's own rules.
</p>
<p class="small muted">
    {{ $booking->reference }} · printed {{ now()->format('M j, Y g:i A') }}
</p>

</body>
</html>
