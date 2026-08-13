{{--
    The printable hotel voucher — what a guest hands over at the front desk.

    Built as the e-ticket's sibling: same masthead, same reference band, same section
    grammar, same guest copy. An agency issues both, and a guest should be able to tell
    at a glance that the two documents came from the same desk.

    Where it departs from the e-ticket, it departs because a stay is not a flight. There
    is no route to draw, so the dates are stated rather than diagrammed; and there is a
    section a ticket never needs — what the guest still owes when they arrive.

    Standalone: no app layout, no Tailwind, no JavaScript. Structure stays table-based
    and the CSS stays close to 2.1 (no flex, no grid, no custom properties) so the same
    template can be handed to a PDF renderer without being redrawn.
--}}
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $voucher->title() }} — {{ $booking->reference }}</title>
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

        /* ---- the stay ---- */
        .card { border: 1px solid #e5e7eb; border-radius: 6px; padding: 14px 16px; margin-bottom: 8px; }
        .card-head { border-bottom: 1px solid #f3f4f6; padding-bottom: 9px; margin-bottom: 12px; }
        .hotel-name { font-size: 13px; font-weight: bold; color: #13144a; }
        .stars { font-size: 11px; color: #d97706; letter-spacing: .06em; }
        .hotel-line { font-size: 10.5px; color: #6b7280; padding-top: 2px; }
        .chip {
            font-size: 9px; letter-spacing: .08em; text-transform: uppercase;
            color: #4b5563; background: #f3f4f6; padding: 3px 8px; border-radius: 10px;
        }

        /* The dates a guest reads first, so they are the largest thing in the card. */
        .d-label { font-size: 8.5px; letter-spacing: .14em; text-transform: uppercase; color: #9ca3af; padding-bottom: 3px; }
        .d-date  { font-size: 17px; font-weight: bold; color: #111827; line-height: 1.15; }
        .d-day   { font-size: 10px; color: #6b7280; padding-top: 2px; }
        .d-time  { font-size: 10px; color: #9ca3af; }
        .d-count { font-size: 17px; font-weight: bold; color: #13144a; line-height: 1.15; }

        .card-foot {
            border-top: 1px solid #f3f4f6; margin-top: 12px; padding-top: 9px;
            font-size: 10px; color: #6b7280;
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

        .room-no { font-size: 11.5px; font-weight: bold; color: #13144a; letter-spacing: .02em; }
        .guest { font-size: 11px; color: #1f2937; }
        .lead { font-size: 9px; letter-spacing: .06em; text-transform: uppercase; color: #2d31a6; }

        /* ---- money ---- */
        .fare { font-size: 10.5px; }
        .fare td { padding: 5px 0; }
        .fare .sub td { border-top: 1px solid #f3f4f6; font-weight: bold; }
        .fare .tot td {
            border-top: 2px solid #13144a; padding-top: 9px;
            font-size: 13px; font-weight: bold; color: #13144a;
        }

        /* What is not ours to collect. Set apart, because it is the line that surprises. */
        .due { border: 1px solid #fde68a; background: #fffbeb; border-radius: 6px; padding: 12px 14px; }
        .due-title { font-size: 10.5px; font-weight: bold; color: #92400e; padding-bottom: 2px; }
        .due-note { font-size: 10px; color: #b45309; padding-bottom: 6px; }
        .due table { font-size: 10.5px; color: #92400e; }
        .due td { padding: 3px 0; }
        .due .tot td { border-top: 1px solid #fde68a; padding-top: 6px; font-weight: bold; }

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

        .terms { font-size: 10px; color: #4b5563; }
        .terms p { margin: 0 0 5px; }
        .terms ul, .terms ol { margin: 4px 0 8px; padding-left: 16px; }
        .terms li { margin: 0 0 2px; }

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
            .card, .data tr, .help, .due { page-break-inside: avoid; }
        }
    </style>
</head>

<body>

<div class="actions">
    <button type="button" onclick="window.print()">Print</button>
    <a href="{{ route('bookings.show', $booking) }}">Back to booking</a>
    @if ($voucher->withPrices)
        <a href="{{ route('hotels.bookings.voucher', [$booking, 'prices' => 0]) }}">Guest copy (hide rates)</a>
    @else
        <a href="{{ route('hotels.bookings.voucher', $booking) }}">Show rates</a>
    @endif
</div>

@php
    $issuer = $voucher->issuer();
    $property = $voucher->property();
    $hours = $voucher->deskHours();
    $charges = $voucher->charges();
@endphp

<div class="sheet">

    {{-- Masthead. The agency's brand, not ours — they are who the guest deals with. --}}
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
                <p class="doc-type">{{ $voucher->title() }}</p>
                @if ($booking->environment === 'live')
                    <p class="doc-sub">Issued {{ now()->format('j M Y') }}</p>
                @else
                    {{-- Literal, not text-transform: if the stylesheet is ever lost this
                         warning must still be the loudest thing on the page. --}}
                    <p class="warn-env">TEST ENVIRONMENT</p>
                    <p class="warn-env">NOT A REAL BOOKING</p>
                @endif
            </td>
        </tr>
    </table>

    <div class="hairline"></div>

    {{-- The numbers a front desk asks for, in the order they ask for them. --}}
    <table class="band">
        <tr>
            <td>
                <p class="label">Confirmation number</p>
                <p class="value mono">{{ $stay->confirmation_number }}</p>
            </td>
            <td>
                <p class="label">Hotel reference</p>
                {{-- Absent until TBO issues it, which is only within 30 days of
                     check-in. An em dash says "not yet", a blank says "we lost it". --}}
                <p class="value-sm mono">{{ $stay->hotel_confirmation_number ?: '—' }}</p>
            </td>
            <td>
                <p class="label">Booking reference</p>
                <p class="value-sm mono">{{ $booking->reference }}</p>
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

    @if ($notice = $voucher->notice())
        <p class="notice">{{ $notice }}</p>
    @endif

    {{-- The stay --}}
    <p class="section">Your stay</p>

    <div class="card">
        <table class="card-head">
            <tr>
                <td>
                    <p class="hotel-name">
                        {{ $property['name'] }}
                        @if ($property['rating'])
                            <span class="stars">@for ($i = 0; $i < $property['rating']; $i++)&#9733;@endfor</span>
                        @endif
                    </p>
                    @if ($property['address'])
                        <p class="hotel-line">{{ $property['address'] }}</p>
                    @endif
                </td>
                <td class="right nowrap" style="width: 130px;">
                    <span class="chip">{{ $stay->is_refundable ? 'Refundable' : 'Non-refundable' }}</span>
                </td>
            </tr>
        </table>

        {{-- Stated, not diagrammed: a stay has no route, and the two dates plus the
             count between them is the whole of what a guest needs to read. --}}
        <table>
            <tr>
                <td style="width: 30%;">
                    <p class="d-label">Check-in</p>
                    <p class="d-date">{{ $voucher->checkIn()->format('j M Y') }}</p>
                    <p class="d-day">{{ $voucher->checkIn()->format('l') }}</p>
                    @if ($hours['from'])
                        <p class="d-time">
                            from {{ $hours['from'] }}@if ($hours['until']) until {{ $hours['until'] }}@endif
                        </p>
                    @endif
                </td>
                <td style="width: 30%;">
                    <p class="d-label">Check-out</p>
                    <p class="d-date">{{ $voucher->checkOut()->format('j M Y') }}</p>
                    <p class="d-day">{{ $voucher->checkOut()->format('l') }}</p>
                    @if ($hours['out'])
                        <p class="d-time">by {{ $hours['out'] }}</p>
                    @endif
                </td>
                <td style="width: 20%;">
                    <p class="d-label">Nights</p>
                    <p class="d-count">{{ $stay->nights }}</p>
                </td>
                <td style="width: 20%;">
                    <p class="d-label">Rooms</p>
                    <p class="d-count">{{ $stay->rooms_count }}</p>
                </td>
            </tr>
        </table>

        <table class="card-foot">
            <tr>
                <td>{{ implode(' · ', $voucher->inclusions()) }}</td>
                <td class="right">Property code <span class="mono">{{ $property['code'] }}</span></td>
            </tr>
        </table>
    </div>

    {{-- Rooms and guests --}}
    <p class="section">Rooms and guests</p>
    <table class="data">
        <tr>
            <th style="width: 10%;">Room</th>
            <th style="width: 42%;">Room type</th>
            <th>Guests</th>
        </tr>
        @foreach ($voucher->rooms() as $room)
            <tr>
                <td><p class="room-no">{{ $room['index'] + 1 }}</p></td>
                <td>{{ $room['name'] ?? '—' }}</td>
                <td>
                    @forelse ($room['guests'] as $guest)
                        <p class="guest">
                            {{ strtoupper($guest['name']) }}
                            <span class="small muted">{{ $guest['type'] }}</span>
                            @if ($guest['isLead'])<span class="lead">lead</span>@endif
                        </p>
                    @empty
                        <span class="muted">—</span>
                    @endforelse
                </td>
            </tr>
        @endforeach
    </table>

    {{-- What we did not collect. On the voucher because the desk will ask for it. --}}
    @if ($due = $voucher->payableAtProperty())
        <p class="section">Payable at the hotel</p>
        <div class="due">
            <p class="due-title">To be settled directly with the property</p>
            <p class="due-note">Not included in the amount paid to {{ $issuer['name'] }}.</p>
            <table>
                @foreach ($due as $item)
                    <tr>
                        <td>
                            {{ $item['description'] }}
                            @if ($item['count'] > 1)
                                <span class="small">&times; {{ $item['count'] }} rooms</span>
                            @endif
                        </td>
                        <td class="right nowrap">
                            {{ $item['currency'] ?: $booking->currency }} {{ number_format((float) $item['total'], 2) }}
                        </td>
                    </tr>
                @endforeach
                <tr class="tot">
                    <td>Estimated total due at property</td>
                    <td class="right nowrap">{{ $booking->currency }} {{ number_format($voucher->payableAtPropertyTotal(), 2) }}</td>
                </tr>
            </table>
        </div>
    @endif

    {{-- Rates. Absent entirely on the guest copy. --}}
    @if ($voucher->withPrices)
        <p class="section">Rate summary</p>
        @php
            // Built here rather than inline: a Blade directive glued to a word — the
            // "night@endif" this used to end with — is not a directive at all.
            $basis = $charges['nights'].' '.Str::plural('night', $charges['nights'])
                .' × '.$charges['rooms'].' '.Str::plural('room', $charges['rooms']);

            if ($charges['nightly']) {
                $basis .= ', '.$booking->currency.' '.number_format($charges['nightly'], 2).' per room per night';
            }
        @endphp

        <table class="fare">
            <tr>
                <td class="muted">
                    Accommodation
                    <span class="small">({{ $basis }})</span>
                </td>
                <td class="right nowrap">{{ $booking->currency }} {{ number_format($charges['accommodation'], 2) }}</td>
            </tr>
            @if ($charges['tax'] > 0)
                <tr>
                    <td class="muted">Taxes and fees</td>
                    <td class="right nowrap">{{ $booking->currency }} {{ number_format($charges['tax'], 2) }}</td>
                </tr>
            @endif
            <tr class="tot">
                <td class="right">Total paid</td>
                <td class="right nowrap">{{ $booking->currency }} {{ number_format($charges['total'], 2) }}</td>
            </tr>
        </table>
    @endif

    {{-- Cancellation, from the PreBook policy §18 makes final. --}}
    @if ($schedule = $voucher->cancellation())
        <p class="section">Cancellation policy</p>
        <table class="data">
            <tr>
                <th style="width: 24%;">Cancelled on or after</th>
                <th>Charge</th>
            </tr>
            @foreach ($schedule as $policy)
                <tr>
                    <td class="nowrap">{{ $policy['from']->format('j M Y') }}</td>
                    <td>
                        @if ($policy['room'] !== null)
                            <span class="muted small">Room {{ $policy['room'] }} &middot;</span>
                        @endif
                        @if ($policy['charge'] <= 0)
                            <strong>No charge</strong> — cancel free of charge
                        @elseif ($policy['chargeType'] === 'Percentage')
                            <strong>{{ rtrim(rtrim(number_format($policy['charge'], 2), '0'), '.') }}%</strong> of the stay
                        @else
                            <strong>{{ $booking->currency }} {{ number_format($policy['charge'], 2) }}</strong>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    {{-- The property's own norms, in its own words as TBO supplied them. --}}
    @if ($conditions = $voucher->conditions())
        <p class="section">Hotel conditions</p>
        <div class="terms">
            @foreach ($conditions as $condition)
                <div>{!! $condition !!}</div>
            @endforeach
        </div>
    @endif

    {{-- Who to call. The whole reason the agency's details are on this page. --}}
    <table class="help">
        <tr>
            <td>
                @php
                    $reach = collect([$issuer['email'], $issuer['phone']])->filter()->implode(' or ');
                    $guest = $voucher->contact();
                    $guestLine = collect([$guest['name'], $guest['phone'], $guest['email']])->filter()->implode(' · ');
                @endphp
                <p class="help-title">Need help with this booking?</p>
                <p class="help-line">
                    Contact <strong>{{ $issuer['name'] }}</strong>{{ $reach ? ' at '.$reach : '' }}.
                </p>
                @if ($guestLine)
                    <p class="help-line small muted">Booked for {{ $guestLine }}</p>
                @endif
            </td>
            <td class="right" style="width: 34%;">
                <p class="help-line small muted">Show at the desk</p>
                <p class="mono" style="font-size: 12px; color: #13144a;">{{ $stay->confirmation_number }}</p>
            </td>
        </tr>
    </table>

    <p class="foot">
        Please present this voucher and photo identification at check-in. The room is held in the lead
        guest's name; anything settled at the property is in addition to the amount above, and changes,
        no-shows and cancellations are governed by the policy and hotel conditions stated here.
        <br>
        {{ $booking->reference }} &middot; printed {{ now()->format('j M Y, H:i') }}
    </p>

</div>

</body>
</html>
