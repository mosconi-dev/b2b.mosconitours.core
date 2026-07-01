<?php

namespace App\Console\Commands;

use App\Enums\CabinClass;
use App\Enums\TripType;
use App\Services\TboAir\DTO\FlightOffer;
use App\Services\TboAir\DTO\SearchInput;
use App\Services\TboAir\Exceptions\TboAirException;
use App\Services\TboAir\TboAirService;
use App\Support\Airports;
use Illuminate\Console\Command;

class TboAirSearchCommand extends Command
{
    protected $signature = 'tboair:search
        {origin : Origin IATA code or "City (XXX)"}
        {destination : Destination IATA code or "City (XXX)"}
        {departure : Departure date (YYYY-MM-DD)}
        {--adults=1}
        {--children=0}
        {--infants=0}
        {--cabin=economy : economy|premium|business|first}
        {--return= : Return date for a round-trip (YYYY-MM-DD)}';

    protected $description = 'Run a live TBO Air flight search (smoke test on a whitelisted server).';

    public function handle(TboAirService $service): int
    {
        $return = $this->option('return') ?: null;

        $input = new SearchInput(
            tripType: $return ? TripType::Round : TripType::OneWay,
            cabin: CabinClass::tryFrom((string) $this->option('cabin')) ?? CabinClass::Economy,
            adults: (int) $this->option('adults'),
            children: (int) $this->option('children'),
            infants: (int) $this->option('infants'),
            segments: [[
                'origin' => Airports::extractCode($this->argument('origin')) ?? '',
                'destination' => Airports::extractCode($this->argument('destination')) ?? '',
                'departure' => $this->argument('departure'),
            ]],
            returnDate: $return,
        );

        $segment = $input->segments[0];
        $this->info("Searching {$segment['origin']} → {$segment['destination']} on {$segment['departure']} ...");

        try {
            $result = $service->search($input);
        } catch (TboAirException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        /** @var array<int, FlightOffer> $offers */
        $offers = $result['offers'];

        $this->line('TraceId: '.($result['traceId'] ?? '—'));
        $this->line('Results: '.count($offers));

        if ($offers === []) {
            $this->warn('No flights returned.');

            return self::SUCCESS;
        }

        $this->table(
            ['Airline', 'Flight', 'From', 'Dep', 'To', 'Arr', 'Stops', 'Dur(m)', 'Fare'],
            array_map(fn (FlightOffer $o): array => [
                $o->airlineName ?: $o->airlineCode,
                implode(', ', $o->flightNumbers),
                $o->departure['code'],
                $o->departure['time'],
                $o->arrival['code'],
                $o->arrival['time'],
                $o->stops,
                $o->duration,
                $result['currency'].' '.number_format($o->price['offeredFare'], 2),
            ], $offers),
        );

        return self::SUCCESS;
    }
}
