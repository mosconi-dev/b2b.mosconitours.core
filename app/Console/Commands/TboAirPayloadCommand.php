<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\TboAir\TboAirConfig;
use App\Services\TboAir\TboAirService;
use App\Services\TboAir\TboBookPayload;
use Illuminate\Console\Command;
use Throwable;

/**
 * Inspect the Book/Ticket request for a booking **without sending it**.
 *
 * Built for certification: each case can be checked before it is issued, so a
 * malformed request is caught while it still costs nothing. No network call is made
 * — the token is a placeholder, because the payload is the thing under inspection.
 */
class TboAirPayloadCommand extends Command
{
    protected $signature = 'tboair:payload
        {booking : booking id or reference (e.g. 6 or MT-I5NHR6I0)}
        {--ticket : build the Ticket variant instead of Book}
        {--pnr= : the held PNR, for a non-LCC Ticket}
        {--json : print the full payload as JSON}';

    protected $description = 'Dry-run the TBO Book/Ticket payload for a booking. Sends nothing.';

    private const PLACEHOLDER_TOKEN = '<token-injected-at-send-time>';

    public function handle(TboAirService $tbo): int
    {
        $booking = $this->resolveBooking();

        if ($booking === null) {
            $this->error('No booking found for ['.$this->argument('booking').'].');

            return self::FAILURE;
        }

        $pnr = $this->option('pnr') ?: ($this->option('ticket') ? $booking->pnr : null);

        try {
            $payload = TboBookPayload::for(
                $booking,
                self::PLACEHOLDER_TOKEN,
                (string) data_get(TboAirConfig::for($booking->environment), 'ip_address'),
                'tboair:payload (dry run)',
                $pnr,
            );
        } catch (Throwable $e) {
            $this->error('Payload could not be built: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->summarise($booking, $payload, $tbo);

        if ($this->option('json')) {
            $this->newLine();
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return $this->report($booking, $payload, $tbo) ? self::SUCCESS : self::FAILURE;
    }

    private function resolveBooking(): ?Booking
    {
        $key = (string) $this->argument('booking');

        return ctype_digit($key)
            ? Booking::find((int) $key)
            : Booking::where('reference', strtoupper($key))->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function summarise(Booking $booking, array $payload, TboAirService $tbo): void
    {
        $it = $payload['Itinerary'];
        $call = $this->option('ticket') ? 'Ticket' : 'Book';

        $this->line("Booking   : {$booking->reference}  ({$booking->status->value})");
        $this->line('Call      : '.$call.($booking->is_lcc ? '  [LCC — Ticket books and issues in one]' : '  [non-LCC — Book, then Ticket]'));
        $this->line('Env       : '.$booking->environment.'  (currently resolved: '.$tbo->environment().')');
        $this->line('TrackingId: '.($payload['TrackingId'] ?? '—').'   ResultId: '.strlen((string) $payload['ResultId']).' chars');
        $this->line('PNR sent  : '.($payload['PNR'] === '' ? '(empty — creating one)' : $payload['PNR']));
        $this->newLine();

        $this->line('Segments  : '.count($it['Segments_BE']));
        foreach ($it['Segments_BE'] as $i => $s) {
            $this->line(sprintf(
                '  %d. %-6s %s → %-4s  dep %s  seats=%s',
                $i + 1,
                data_get($s, 'Airline.AirlineCode').data_get($s, 'Airline.FlightNumber'),
                data_get($s, 'Origin.Airport.AirportCode'),
                data_get($s, 'Destination.Airport.AirportCode'),
                (string) data_get($s, 'Origin.DepTime'),
                $s['NoOfSeatAvailable'] ?? '— MISSING',
            ));
        }

        $this->newLine();
        $this->line('Passengers: '.count($it['Passenger']));
        foreach ($it['Passenger'] as $i => $p) {
            $this->line(sprintf(
                '  %d. %s %s   Title=%s Type=%s Gender=%s%s  bag=%d meal=%d',
                $i + 1,
                $p['FirstName'],
                $p['LastName'],
                $p['Title'],
                $p['Type'],
                $p['Gender'],
                $p['IsLeadPax'] ? '  [lead]' : '',
                count($p['Baggage']),
                count($p['MealDynamic']),
            ));
        }

        $this->newLine();
        $this->line(sprintf(
            'Fare      : %s %s   (OfferedFare on each passenger as Fare_BE)',
            data_get($it['Passenger'][0] ?? [], 'Fare_BE.Currency', $booking->currency),
            number_format((float) data_get($it['Passenger'][0] ?? [], 'Fare_BE.OfferedFare', 0), 2),
        ));
        $this->line('Booking total (charged to the agency): '.$booking->currency.' '.number_format((float) $booking->total_amount, 2));
        $this->line(sprintf(
            'Flags     : IsLcc=%s SearchType=%s ResultType=%s FareType=%s Validating=%s',
            var_export($it['IsLcc'], true),
            $it['SearchType'],
            var_export($it['ResultType'], true),
            var_export($it['FareType'], true),
            var_export($it['ValidatingAirlineCode'], true),
        ));
    }

    /**
     * Pre-flight checks. Returns false when something would very likely be rejected.
     *
     * @param  array<string, mixed>  $payload
     */
    private function report(Booking $booking, array $payload, TboAirService $tbo): bool
    {
        $it = $payload['Itinerary'];
        $errors = [];
        $warnings = [];

        // Blockers — TBO would reject, or we would refuse before calling.
        if ($booking->environment !== $tbo->environment()) {
            $errors[] = "Environment mismatch: booking is {$booking->environment}, current is {$tbo->environment()}. It cannot be sent from here.";
        }

        $expected = $booking->is_lcc ? [BookingStatus::Quoted] : [BookingStatus::Quoted, BookingStatus::Booked];
        if (! in_array($booking->status, $expected, true)) {
            $errors[] = "Status is {$booking->status->value}; nothing further can be sent.";
        }

        if ($this->option('ticket') && ! $booking->is_lcc && ! filled($payload['PNR'])) {
            $errors[] = 'A non-LCC Ticket needs the held PNR — run Book first, or pass --pnr.';
        }

        foreach ($it['Passenger'] as $i => $p) {
            $n = $i + 1;
            foreach (['FirstName', 'LastName', 'AddressLine1', 'Email', 'Mobile1'] as $field) {
                if (! filled($p[$field])) {
                    $errors[] = "Passenger {$n}: {$field} is empty, and TBO requires it.";
                }
            }
            if (! filled(data_get($p, 'Country.CountryCode'))) {
                $errors[] = "Passenger {$n}: no country on the address.";
            }
        }

        // Warnings — likely fine, but worth seeing before a certification run.
        foreach ($it['Segments_BE'] as $i => $s) {
            if (! isset($s['NoOfSeatAvailable'])) {
                $warnings[] = 'Segment '.($i + 1).' has no NoOfSeatAvailable. TBO documents it as mandatory; it is captured at search, so re-do the search with a fresh browser cache if this persists.';
            }
        }

        if ($it['ResultType'] === null) {
            $warnings[] = 'ResultType is null — it is carried from the search response and TBO may expect it.';
        }

        if (count($it['Passenger']) === 0) {
            $errors[] = 'No passengers.';
        } elseif (! collect($it['Passenger'])->contains('IsLeadPax', true)) {
            $errors[] = 'No lead passenger; TBO expects exactly one.';
        }

        $this->newLine();

        foreach ($warnings as $w) {
            $this->warn('WARN  '.$w);
        }

        foreach ($errors as $e) {
            $this->error('ERROR '.$e);
        }

        if ($errors === []) {
            $this->info($warnings === []
                ? 'Payload looks sendable. Nothing was sent.'
                : 'No blockers found, but see the warnings above. Nothing was sent.');
        }

        return $errors === [];
    }
}
