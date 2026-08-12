{{--
    The printable e-ticket.

    Laid out the way an airline lays one out — a branded header, the reference numbers
    in a row of their own, then one card per flight showing the route as times and
    airport codes rather than as rows of a grid. The traveller reads the times first, so
    the times are the largest thing on the page.

    Ink is deliberate: hairlines and type weight carry the structure, not filled panels.
    This is a document people print, often on an office laser, and solid colour bands
    across the top of every copy are the first thing to look cheap and the first thing
    to drink toner.

    Standalone: no app layout, no Tailwind, no JavaScript. Structure stays table-based
    and the CSS stays close to 2.1 (no flex, no grid, no custom properties) so the same
    template can be handed to a PDF renderer without being redrawn.
--}}
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $ticket->title() }} — {{ $booking->reference }}</title>
    <style>
        @page { margin: 14mm; }

        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.45;
            color: #1f2937;
            background: #f3f4f6;
            margin: 0;
            padding: 24px 0;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .sheet {
            width: 760px;
            margin: 0 auto;
            background: #ffffff;
            padding: 32px 36px 28px;
        }

        p { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; text-align: left; padding: 0; }

        /* ---- masthead ---- */
        .logo { max-height: 52px; max-width: 150px; }
        .issuer-name { font-size: 15px; font-weight: bold; color: #13144a; letter-spacing: .01em; }
        .issuer-line { font-size: 10.5px; color: #6b7280; }

        .doc-type {
            font-size: 17px; font-weight: bold; color: #13144a;
            letter-spacing: .16em; text-transform: uppercase;
        }
        .doc-sub { font-size: 10px; color: #9ca3af; letter-spacing: .04em; }

        .hairline { height: 1px; background: #e5e7eb; margin: 14px 0 0; font-size: 0; }

        /* ---- reference row ---- */
        .band { padding: 14px 0 16px; border-bottom: 1px solid #e5e7eb; }
        .band .label {
            font-size: 8.5px; letter-spacing: .14em; text-transform: uppercase;
            color: #9ca3af; padding-bottom: 3px;
        }
        .band .value { font-size: 15px; font-weight: bold; letter-spacing: .04em; color: #13144a; }
        .band .value-sm { font-size: 12px; font-weight: bold; color: #1f2937; }

        /* ---- section headings ---- */
        .section {
            font-size: 9px; font-weight: bold; letter-spacing: .16em;
            text-transform: uppercase; color: #9ca3af;
            margin: 24px 0 8px;
        }

        /* ---- flight card ---- */
        .trip-head { margin: 0 0 7px; }
        .trip-route { font-size: 12.5px; font-weight: bold; color: #13144a; }
        .trip-date { font-size: 10.5px; color: #6b7280; }

        .card { border: 1px solid #e5e7eb; border-radius: 6px; padding: 14px 16px; margin-bottom: 8px; }
        .card-head { border-bottom: 1px solid #f3f4f6; padding-bottom: 9px; margin-bottom: 12px; }
        .carrier { font-size: 12px; font-weight: bold; color: #13144a; }
        .flightno {
            font-family: "DejaVu Sans Mono", Consolas, monospace;
            font-size: 11px; color: #4b5563; letter-spacing: .04em;
        }
        .chip {
            font-size: 9px; letter-spacing: .08em; text-transform: uppercase;
            color: #4b5563; background: #f3f4f6; padding: 3px 8px; border-radius: 10px;
        }

        .t-time { font-size: 21px; font-weight: bold; color: #111827; line-height: 1.1; }
        .t-code {
            font-size: 12px; font-weight: bold; color: #13144a;
            letter-spacing: .1em; padding-top: 2px;
        }
        .t-place { font-size: 10px; color: #6b7280; padding-top: 3px; }
        .t-date  { font-size: 10px; color: #9ca3af; }

        .leg-mid { padding: 0 10px; }
        .leg-dur { font-size: 10px; color: #6b7280; padding-bottom: 4px; }
        .leg-line { border-top: 1px solid #d1d5db; font-size: 0; height: 1px; }
        .leg-stop { font-size: 9px; color: #9ca3af; letter-spacing: .1em; text-transform: uppercase; padding-top: 4px; }

        .card-foot {
            border-top: 1px solid #f3f4f6; margin-top: 12px; padding-top: 9px;
            font-size: 10px; color: #6b7280;
        }

        .layover {
            text-align: center; font-size: 10px; color: #92400e;
            background: #fffbeb; border: 1px solid #fde68a; border-radius: 4px;
            padding: 5px 10px; margin-bottom: 8px;
        }

        /* ---- data tables ---- */
        .data { font-size: 10.5px; }
        .data th {
            font-size: 8.5px; letter-spacing: .12em; text-transform: uppercase;
            color: #9ca3af; font-weight: bold;
            border-bottom: 1px solid #e5e7eb; padding: 0 10px 6px 0;
        }
        .data td { padding: 9px 10px 9px 0; border-bottom: 1px solid #f3f4f6; }
        .data td:last-child, .data th:last-child { padding-right: 0; }

        .pax-name { font-size: 11.5px; font-weight: bold; color: #13144a; letter-spacing: .02em; }
        .tkt {
            font-family: "DejaVu Sans Mono", Consolas, monospace;
            font-size: 11.5px; font-weight: bold; color: #13144a;
        }

        /* ---- fare ---- */
        .fare { font-size: 10.5px; }
        .fare td { padding: 5px 0; }
        .fare .grp td { padding-top: 12px; font-weight: bold; color: #13144a; }
        .fare .sub td { border-top: 1px solid #f3f4f6; font-weight: bold; }
        .fare .tot td {
            border-top: 2px solid #13144a; padding-top: 9px;
            font-size: 13px; font-weight: bold; color: #13144a;
        }

        /* ---- help ---- */
        .help { background: #f9fafb; border-radius: 6px; padding: 14px 16px; margin-top: 26px; }
        .help-title { font-size: 10.5px; font-weight: bold; color: #13144a; padding-bottom: 3px; }
        .help-line { font-size: 10.5px; color: #4b5563; }

        .notice {
            border-left: 3px solid #f59e0b; background: #fffbeb;
            padding: 9px 12px; margin: 16px 0 0; font-size: 10.5px; color: #92400e;
        }
        .warn-env {
            font-size: 9.5px; font-weight: bold; letter-spacing: .1em;
            color: #b45309; text-transform: uppercase;
        }

        .muted  { color: #6b7280; }
        .small  { font-size: 10px; }
        .right  { text-align: right; }
        .center { text-align: center; }
        .nowrap { white-space: nowrap; }
        .mono   { font-family: "DejaVu Sans Mono", Consolas, monospace; }

        .foot { margin-top: 18px; border-top: 1px solid #f3f4f6; padding-top: 12px; font-size: 9.5px; color: #9ca3af; }

        /* ---- screen-only controls ---- */
        .actions { width: 760px; margin: 0 auto 12px; }
        .actions a, .actions button {
            font: inherit; font-size: 11px; color: #2d31a6; background: none;
            border: none; padding: 0; margin-right: 16px; cursor: pointer; text-decoration: underline;
        }

        @media print {
            body { background: #ffffff; padding: 0; }
            .sheet { width: auto; padding: 0; }
            .actions { display: none; }
            .card, .data tr, .help { page-break-inside: avoid; }
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

@php $issuer = $ticket->issuer(); @endphp

<div class="sheet">

    {{-- Masthead. The agency's brand, not ours — they are who the traveller deals with. --}}
    <table>
        <tr>
            <td style="width: 64px;">
                <img class="logo" src="{{ $issuer['logo'] }}" alt="{{ $issuer['name'] }}">
            </td>
            <td style="padding-left: 14px;">
                <p class="issuer-name">{{ $issuer['name'] }}</p>
                @if ($issuer['address'])
                    <p class="issuer-line">{{ $issuer['address'] }}</p>
                @endif
                <p class="issuer-line">
                    @if ($issuer['email']){{ $issuer['email'] }}@endif
                    @if ($issuer['email'] && $issuer['phone']) &middot; @endif
                    @if ($issuer['phone']){{ $issuer['phone'] }}@endif
                </p>
            </td>
            <td class="right" style="width: 200px;">
                <p class="doc-type">{{ $ticket->title() }}</p>
                @if ($booking->environment === 'live')
                    <p class="doc-sub">Issued {{ now()->format('j M Y') }}</p>
                @else
                    {{-- Literal, not text-transform: if the stylesheet is ever lost this
                         warning must still be the loudest thing on the page. --}}
                    <p class="warn-env">TEST ENVIRONMENT</p>
                    <p class="warn-env">NOT VALID FOR TRAVEL</p>
                @endif
            </td>
        </tr>
    </table>

    <div class="hairline"></div>

    {{-- The numbers an airline desk asks for, in the order they ask for them. --}}
    <table class="band">
        <tr>
            <td>
                <p class="label">Booking reference</p>
                <p class="value mono">{{ $booking->reference }}</p>
            </td>
            <td>
                <p class="label">Airline PNR</p>
                <p class="value mono">{{ $booking->pnr ?? '—' }}</p>
            </td>
            <td>
                <p class="label">Airline booking ID</p>
                <p class="value-sm mono">{{ $booking->booking_id ?? '—' }}</p>
            </td>
            <td>
                <p class="label">Status</p>
                <p class="value-sm">{{ $booking->status->label() }}</p>
            </td>
            <td class="right">
                <p class="label">Booked</p>
                <p class="value-sm">{{ $booking->created_at?->format('j M Y') }}</p>
            </td>
        </tr>
    </table>

    @if ($notice = $ticket->notice())
        <p class="notice">{{ $notice }}</p>
    @endif

    {{-- Flights --}}
    @foreach ($ticket->trips() as $trip)
        <p class="section">
            @if ($trip['label']){{ $trip['label'] }} flight @else Flight itinerary @endif
        </p>

        <table class="trip-head">
            <tr>
                <td><p class="trip-route">{{ $trip['route'] }}</p></td>
                <td class="right"><p class="trip-date">{{ $trip['date']?->format('l, j F Y') }}</p></td>
            </tr>
        </table>

        @foreach ($trip['segments'] as $segment)
            <div class="card">
                <table class="card-head">
                    <tr>
                        <td>
                            <span class="carrier">{{ $segment['airlineName'] }}</span>
                            <span class="flightno">&nbsp;{{ $segment['flightNumber'] }}</span>
                        </td>
                        <td class="right nowrap">
                            @if ($segment['cabin'])
                                <span class="chip">{{ $segment['cabin'] }}@if ($segment['fareClass']) {{ $segment['fareClass'] }}@endif</span>
                            @endif
                        </td>
                    </tr>
                </table>

                {{-- The route. Times largest, because that is what is read first. --}}
                <table>
                    <tr>
                        <td style="width: 33%;">
                            <p class="t-time">{{ $segment['origin']['at']?->format('H:i') ?? '—' }}</p>
                            <p class="t-code">{{ $segment['origin']['code'] }} &middot; {{ $segment['origin']['city'] }}</p>
                            <p class="t-place">
                                {{ $segment['origin']['airport'] }}@if ($segment['origin']['terminal']), Terminal {{ $segment['origin']['terminal'] }}@endif
                            </p>
                            <p class="t-date">{{ $segment['origin']['at']?->format('D, j M Y') }}</p>
                        </td>

                        <td class="leg-mid center" style="width: 34%;">
                            <p class="leg-dur">{{ $segment['duration'] ?? '' }}</p>
                            <div class="leg-line"></div>
                            <p class="leg-stop">{{ $trip['stops'] === 0 ? 'Direct' : 'Leg '.($loop->iteration).' of '.$loop->count }}</p>
                        </td>

                        <td class="right" style="width: 33%;">
                            <p class="t-time">{{ $segment['destination']['at']?->format('H:i') ?? '—' }}</p>
                            <p class="t-code">{{ $segment['destination']['city'] }} &middot; {{ $segment['destination']['code'] }}</p>
                            <p class="t-place">
                                {{ $segment['destination']['airport'] }}@if ($segment['destination']['terminal']), Terminal {{ $segment['destination']['terminal'] }}@endif
                            </p>
                            <p class="t-date">{{ $segment['destination']['at']?->format('D, j M Y') }}</p>
                        </td>
                    </tr>
                </table>

                <table class="card-foot">
                    <tr>
                        <td>
                            Baggage:
                            {{ $segment['baggage'] ? $segment['baggage'].' checked' : 'no checked allowance' }},
                            {{ $segment['cabinBaggage'] ? $segment['cabinBaggage'].' cabin' : 'no cabin allowance' }}
                        </td>
                        <td class="right">
                            @if ($segment['operatedBy'])<strong>Operated by {{ $segment['operatedBy'] }}</strong>@endif
                            @if ($segment['operatedBy'] && $segment['aircraft']) &middot; @endif
                            @if ($segment['aircraft'])Aircraft {{ $segment['aircraft'] }}@endif
                        </td>
                    </tr>
                </table>
            </div>

            @if ($segment['layoverAfter'])
                <p class="layover">{{ $segment['layoverAfter'] }} connection in {{ $segment['destination']['city'] }} ({{ $segment['destination']['code'] }})</p>
            @endif
        @endforeach
    @endforeach

    {{-- Passengers --}}
    <p class="section">Passengers</p>
    <table class="data">
        <tr>
            <th style="width: 36%;">Name</th>
            <th style="width: 11%;">Type</th>
            <th style="width: 29%;">Travel document</th>
            <th style="width: 24%;" class="right">e-Ticket number</th>
        </tr>
        @foreach ($ticket->passengers() as $i => $passenger)
            <tr>
                <td>
                    <p class="pax-name">{{ $i + 1 }}. {{ strtoupper($passenger['name']) }}</p>
                    @if ($passenger['dateOfBirth'])
                        <p class="small muted">Born {{ $passenger['dateOfBirth'] }}</p>
                    @endif
                </td>
                <td>{{ $passenger['type'] }}</td>
                <td>
                    @if ($passenger['documentNumber'])
                        {{ $passenger['documentLabel'] }} <span class="mono">{{ $passenger['documentNumber'] }}</span>
                        @if ($passenger['documentExpiry'])
                            <p class="small muted">Expires {{ $passenger['documentExpiry'] }}</p>
                        @endif
                        @if ($passenger['nationality'])
                            <p class="small muted">Nationality {{ $passenger['nationality'] }}</p>
                        @endif
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
                <td class="right">
                    @if ($passenger['ticketNumber'])
                        <span class="tkt">{{ $passenger['ticketNumber'] }}</span>
                        @if ($passenger['ticketIssuedAt'])
                            <p class="small muted">Issued {{ $passenger['ticketIssuedAt']->format('j M Y') }}</p>
                        @endif
                    @else
                        <span class="muted">Not issued</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </table>

    {{-- Add-ons --}}
    @if ($addOns = $ticket->addOns())
        <p class="section">Special service requests</p>
        <table class="data">
            <tr>
                <th style="width: 32%;">Passenger</th>
                <th style="width: 14%;">Type</th>
                <th>Description</th>
                <th style="width: 16%;">Route</th>
                @if ($ticket->withPrices)<th class="right" style="width: 16%;">Price</th>@endif
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

    {{-- Fare. Absent entirely on the passenger copy. --}}
    @if ($ticket->withPrices)
        <p class="section">Fare summary</p>
        <table class="fare">
            @foreach ($ticket->fareLines() as $line)
                <tr class="grp">
                    <td colspan="2">{{ $line['label'] }} &times; {{ $line['count'] }}</td>
                </tr>
                <tr>
                    <td class="muted">Base fare <span class="small">({{ $booking->currency }} {{ number_format($line['baseFare'] / $line['count'], 2) }} each)</span></td>
                    <td class="right nowrap">{{ $booking->currency }} {{ number_format($line['baseFare'], 2) }}</td>
                </tr>
                <tr>
                    <td class="muted">Taxes and fees</td>
                    <td class="right nowrap">{{ $booking->currency }} {{ number_format($line['tax'], 2) }}</td>
                </tr>
                <tr class="sub">
                    <td class="right">{{ $line['label'] }} subtotal</td>
                    <td class="right nowrap">{{ $booking->currency }} {{ number_format($line['total'], 2) }}</td>
                </tr>
            @endforeach

            @if ($ticket->addOnTotal() > 0)
                <tr class="sub">
                    <td class="right">Add-ons</td>
                    <td class="right nowrap">{{ $booking->currency }} {{ number_format($ticket->addOnTotal(), 2) }}</td>
                </tr>
            @endif

            {{-- Only when the lines above do not already sum to the total. --}}
            @if (abs($ticket->otherCharges()) >= 0.01)
                <tr>
                    <td class="muted right">Other charges</td>
                    <td class="right nowrap">{{ $booking->currency }} {{ number_format($ticket->otherCharges(), 2) }}</td>
                </tr>
            @endif

            <tr class="tot">
                <td class="right">Total paid</td>
                <td class="right nowrap">{{ $booking->currency }} {{ number_format($ticket->total(), 2) }}</td>
            </tr>
        </table>
    @endif

    {{-- Fare conditions, in the airline's own words as TBO supplied them --}}
    @if ($rules = $ticket->fareRules())
        <p class="section">Fare conditions</p>
        <table class="data">
            @foreach ($rules as $rule)
                <tr>
                    <td style="width: 22%;"><strong>{{ $rule['type'] ?? '' }}</strong></td>
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

    {{-- Who to call. The whole reason the agency's details are on this page. --}}
    <table class="help">
        <tr>
            <td>
                @php
                    $reach = collect([$issuer['email'], $issuer['phone']])->filter()->implode(' or ');
                    $traveller = $ticket->contact();
                    $travellerLine = collect([$traveller['name'], $traveller['phone'], $traveller['email']])
                        ->filter()->implode(' · ');
                @endphp
                <p class="help-title">Need help with this booking?</p>
                <p class="help-line">
                    Contact <strong>{{ $issuer['name'] }}</strong>{{ $reach ? ' at '.$reach : '' }}.
                </p>
                @if ($travellerLine)
                    <p class="help-line small muted">Booked for {{ $travellerLine }}</p>
                @endif
            </td>
            <td class="right" style="width: 34%;">
                <p class="help-line small muted">Quote reference</p>
                <p class="mono" style="font-size: 12px; color: #13144a;">{{ $booking->reference }}</p>
            </td>
        </tr>
    </table>

    <p class="foot">
        Please arrive at the airport in good time and carry the travel document listed above — it must be
        the one presented at check-in. Baggage allowances, changes and refunds are governed by the fare
        conditions and the operating airline's own rules.
        <br>
        {{ $booking->reference }} &middot; printed {{ now()->format('j M Y, H:i') }}
    </p>

</div>

</body>
</html>
