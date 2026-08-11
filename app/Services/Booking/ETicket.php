<?php

namespace App\Services\Booking;

use App\Models\Booking;
use Illuminate\Support\Carbon;

/**
 * Everything a printed e-ticket shows, assembled from one booking.
 *
 * The document is built entirely from what we already stored at quote and ticket time
 * — `quote` for the itinerary, `pax` for the passengers and their ticket numbers,
 * `quote_raw` for the handful of details the normaliser drops (operating carrier,
 * aircraft). Nothing here calls TBO: a passenger standing at a check-in desk with a
 * printout must not depend on the supplier being reachable.
 *
 * It deliberately does **not** always say "e-Ticket". The live system prints that
 * heading over a held, unticketed PNR, which hands the passenger a document claiming a
 * ticket that was never issued. Here the heading follows the evidence — a ticket number
 * — and a held booking is labelled as the reservation it actually is.
 */
class ETicket
{
    private function __construct(
        public readonly Booking $booking,
        public readonly bool $withPrices,
    ) {}

    public static function for(Booking $booking, bool $withPrices = true): self
    {
        return new self($booking, $withPrices);
    }

    /**
     * Ticket numbers are the only proof a ticket exists.
     *
     * TBO's own status fields lie about this — a booking we ticketed successfully still
     * reported `Ticketed: false` — so this reads the per-passenger numbers instead.
     */
    public function isTicketed(): bool
    {
        foreach ($this->booking->pax ?? [] as $passenger) {
            if (filled($passenger['ticketNumber'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    public function title(): string
    {
        return $this->isTicketed() ? 'e-Ticket' : 'Booking Confirmation';
    }

    /**
     * The one-line warning printed under the heading when this is not a ticket yet.
     *
     * A held PNR is a seat reservation the airline will drop if nobody pays for it. The
     * printout has to say so, or it reads exactly like a ticket.
     */
    public function notice(): ?string
    {
        if ($this->isTicketed()) {
            return null;
        }

        return filled($this->booking->pnr)
            ? 'This is a reservation, not a ticket. No ticket has been issued yet and the airline may release these seats until it is.'
            : 'This is a priced quote. Nothing has been reserved with the airline.';
    }

    /**
     * Who issued this document, and how the traveller reaches them.
     *
     * The agency is the customer's counterparty — when a flight moves, they call the
     * agency, not us and not the airline. So the details are resolved rather than
     * printed blank: an agency that has not uploaded a logo still gets a branded
     * document, and one that has not filled in a contact email falls back to the agent
     * who actually made the booking.
     *
     * @return array{name: string, logo: string, email: ?string, phone: ?string, address: ?string}
     */
    public function issuer(): array
    {
        $agency = $this->booking->agency;

        return [
            'name' => $agency?->name ?: config('app.name'),
            'logo' => $agency?->logoUrl() ?: asset('favicon.png'),
            'email' => $agency?->contact_email ?: $this->booking->user?->email,
            'phone' => $agency?->contact_phone ?: null,
            'address' => $agency?->address ?: null,
        ];
    }

    /**
     * Flights grouped the way the passenger reads them: outbound, then return.
     *
     * @return array<int, array{label: ?string, route: string, date: ?Carbon, segments: array<int, array<string, mixed>>}>
     */
    public function trips(): array
    {
        $trips = data_get($this->booking->quote, 'trips', []);
        $flatIndex = 0;
        $out = [];

        foreach ($trips as $trip) {
            $segments = [];

            foreach ($trip['segments'] ?? [] as $segment) {
                $segments[] = $this->segment($segment, $flatIndex++);
            }

            if ($segments === []) {
                continue;
            }

            $out[] = [
                'label' => count($trips) > 1
                    ? (($trip['direction'] ?? null) === 'inbound' ? 'Return' : 'Outbound')
                    : null,
                'route' => sprintf(
                    '%s (%s) to %s (%s)',
                    $segments[0]['origin']['city'],
                    $segments[0]['origin']['code'],
                    $segments[count($segments) - 1]['destination']['city'],
                    $segments[count($segments) - 1]['destination']['code'],
                ),
                'date' => $segments[0]['origin']['at'],
                'stops' => count($segments) - 1,
                'segments' => $segments,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $segment
     * @return array<string, mixed>
     */
    private function segment(array $segment, int $flatIndex): array
    {
        $raw = $this->rawSegment($flatIndex);

        // The marketing carrier sells the seat; the operating carrier flies it, and it
        // is the one whose desk the passenger has to find at the airport. Worth printing
        // whenever the two differ. We only ever get its code, so that is what we show.
        $operating = (string) data_get($raw, 'Airline.OperatingCarrier', '');
        $marketing = (string) ($segment['airlineCode'] ?? '');

        return [
            'airlineName' => $segment['airlineName'] ?? '',
            'airlineCode' => $marketing,
            'flightNumber' => $segment['flightNumber'] ?? '',
            'cabin' => $segment['cabin'] ?? null,
            'fareClass' => $segment['fareClass'] ?? null,
            'aircraft' => data_get($raw, 'Craft') ?: null,
            'operatedBy' => ($operating !== '' && $operating !== $marketing) ? $operating : null,
            'origin' => $this->place($segment['origin'] ?? []),
            'destination' => $this->place($segment['destination'] ?? []),
            'duration' => $this->minutes($segment['duration'] ?? null),
            'baggage' => $this->baggage($segment['baggage'] ?? null),
            'cabinBaggage' => $this->baggage($segment['cabinBaggage'] ?? null),
            'layoverAfter' => $this->minutes($segment['layoverAfter'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $place
     * @return array<string, mixed>
     */
    private function place(array $place): array
    {
        return [
            'city' => $place['city'] ?? ($place['code'] ?? ''),
            'code' => $place['code'] ?? '',
            'airport' => $place['airport'] ?? null,
            'terminal' => filled($place['terminal'] ?? null) ? $place['terminal'] : null,
            'at' => filled($place['time'] ?? null) ? Carbon::parse($place['time']) : null,
        ];
    }

    /**
     * The stored FareQuote segment at a flat position, for the fields the normaliser drops.
     *
     * @return array<string, mixed>
     */
    private function rawSegment(int $flatIndex): array
    {
        $groups = data_get($this->booking->quote_raw, 'Response.Results.Segments', []);
        $flat = [];

        foreach ($groups as $group) {
            foreach ((array) $group as $segment) {
                $flat[] = $segment;
            }
        }

        return is_array($flat[$flatIndex] ?? null) ? $flat[$flatIndex] : [];
    }

    /**
     * Passengers in booking order, each with the ticket number if one was issued.
     *
     * @return array<int, array<string, mixed>>
     */
    public function passengers(): array
    {
        $domestic = (bool) data_get($this->booking->quote, 'isDomestic', false);

        return array_map(function (array $p) use ($domestic): array {
            return [
                'name' => trim(sprintf('%s %s %s', $p['title'] ?? '', $p['firstName'] ?? '', $p['lastName'] ?? '')),
                'type' => $p['type'] ?? 'Adult',
                'dateOfBirth' => $p['dateOfBirth'] ?? null,
                'documentLabel' => $domestic ? 'ID' : 'Passport',
                'documentNumber' => $p['documentNumber'] ?? null,
                'documentExpiry' => $p['documentExpiry'] ?? null,
                'nationality' => $p['nationality'] ?? null,
                'ticketNumber' => $p['ticketNumber'] ?? null,
                'ticketIssuedAt' => filled($p['ticketIssuedAt'] ?? null)
                    ? Carbon::parse($p['ticketIssuedAt'])
                    : null,
            ];
        }, $this->booking->pax ?? []);
    }

    /**
     * Purchased baggage and meals, flattened one row per passenger per item.
     *
     * @return array<int, array<string, mixed>>
     */
    public function addOns(): array
    {
        $rows = [];

        foreach ($this->booking->pax ?? [] as $p) {
            $name = trim(sprintf('%s %s', $p['firstName'] ?? '', $p['lastName'] ?? ''));

            foreach (['baggage' => 'Baggage', 'meal' => 'Meal'] as $key => $label) {
                $item = $p['ssr'][$key] ?? null;

                if (! is_array($item)) {
                    continue;
                }

                $rows[] = [
                    'passenger' => $name,
                    'type' => $label,
                    'description' => $item['label'] ?? '',
                    'route' => trim(($item['origin'] ?? '').' – '.($item['destination'] ?? ''), ' –'),
                    'price' => (float) ($item['price'] ?? 0),
                    'currency' => $item['currency'] ?? $this->booking->currency,
                ];
            }
        }

        return $rows;
    }

    /**
     * The fare, one line per passenger type.
     *
     * TBO's FareBreakdown entry already covers every passenger of its type — `baseFare`
     * and `tax` are the combined figures, not per-head. Dividing gives the each-price;
     * multiplying would double the line, which is exactly the bug the booking page had.
     *
     * @return array<int, array{label: string, count: int, each: float, total: float, baseFare: float, tax: float}>
     */
    public function fareLines(): array
    {
        return array_map(function (array $b): array {
            $count = max((int) ($b['count'] ?? 1), 1);
            $base = (float) ($b['baseFare'] ?? 0);
            $tax = (float) ($b['tax'] ?? 0);

            return [
                'label' => $b['passengerType'] ?? 'Passenger',
                'count' => $count,
                'baseFare' => $base,
                'tax' => $tax,
                'total' => $base + $tax,
                'each' => ($base + $tax) / $count,
            ];
        }, data_get($this->booking->quote, 'fareBreakdown', []));
    }

    public function addOnTotal(): float
    {
        return (float) $this->booking->ancillary_total;
    }

    /**
     * Whatever the total contains that the per-passenger lines do not.
     *
     * The fare lines add up to TBO's `PublishedFare`, but we charge — and store — its
     * `OfferedFare`, and the two differ. On PNR 984XIX that gap is ten centavos. Small,
     * but a printed document whose subtotals do not sum to its own total reads as a
     * mistake, so the difference is shown as a line rather than left to be noticed.
     */
    public function otherCharges(): float
    {
        $lines = array_sum(array_column($this->fareLines(), 'total'));

        return round($this->total() - $lines - $this->addOnTotal(), 2);
    }

    public function total(): float
    {
        return (float) $this->booking->total_amount;
    }

    /**
     * The airline's cancellation/reissue conditions as TBO worded them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fareRules(): array
    {
        return data_get($this->booking->quote, 'miniFareRules', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function contact(): array
    {
        $contact = $this->booking->contact ?? [];
        $lead = collect($this->booking->pax ?? [])->firstWhere('isLeadPax', true)
            ?? ($this->booking->pax[0] ?? []);

        return [
            'name' => trim(sprintf('%s %s', $lead['firstName'] ?? '', $lead['lastName'] ?? '')),
            'email' => $contact['email'] ?? null,
            'phone' => trim(sprintf(
                '%s %s',
                filled($contact['mobileCountryCode'] ?? null) ? '+'.$contact['mobileCountryCode'] : '',
                $contact['phone'] ?? '',
            )),
            'address' => collect([
                $contact['addressLine1'] ?? null,
                $contact['addressLine2'] ?? null,
                $contact['city'] ?? null,
                $contact['countryCode'] ?? null,
            ])->filter()->implode(', '),
        ];
    }

    private function minutes(mixed $minutes): ?string
    {
        $minutes = (int) $minutes;

        if ($minutes <= 0) {
            return null;
        }

        return intdiv($minutes, 60).'h '.($minutes % 60).'m';
    }

    /** TBO writes "0 KG" for an allowance that does not exist; that is not a baggage allowance. */
    private function baggage(?string $baggage): ?string
    {
        $baggage = trim((string) $baggage);

        return ($baggage === '' || (float) $baggage === 0.0) ? null : $baggage;
    }
}
