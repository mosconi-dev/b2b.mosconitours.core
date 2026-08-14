<?php

namespace App\Console\Commands;

use App\Services\TboHotel\Exceptions\TboHotelException;
use App\Services\TboHotel\TboHotelConfig;
use App\Services\TboHotel\TboHotelService;
use Illuminate\Console\Command;

/**
 * Proves the TBO Holidays credentials and, more importantly, the base URL.
 *
 * The specification and the system live today disagree about the staging host — on
 * both path and scheme — and the spec is the newer of the two. Rather than reason
 * about which is right, make one cheap call and read the answer.
 *
 * CountryList is a GET with no body and CityList is a POST with one, so `--country`
 * exercises both verbs; a base URL that answers one and not the other is a real
 * possibility worth catching here rather than in Phase 2.
 */
class TboHotelPingCommand extends Command
{
    protected $signature = 'tbohotel:ping {--country= : Also fetch this country\'s cities, to exercise the POST path}';

    protected $description = 'Check TBO Holidays connectivity and credentials by calling CountryList.';

    public function handle(TboHotelService $service): int
    {
        $env = $service->environment();
        $config = TboHotelConfig::for($env);

        $this->line('Env      : '.$env);
        $this->line('Base URL : '.($config['base_url'] ?: '(not configured)'));
        $this->line('User     : '.($config['username'] ?: '(not set)'));
        $this->line('Endpoint : '.$service->url('countrylist').'  [GET]');
        $this->newLine();

        if (blank($config['username']) || blank($config['password'])) {
            $this->error("No {$env} credentials configured. Set TBOHOTEL_".strtoupper($env).'_USERNAME and _PASSWORD.');

            return self::FAILURE;
        }

        $countries = $this->timed(
            fn (): array => $service->countries(),
            'CountryList',
        );

        if ($countries === null) {
            return self::FAILURE;
        }

        $this->line('Countries: '.count($countries).' — e.g. '.collect($countries)->take(3)
            ->map(fn (array $c): string => "{$c['code']} {$c['name']}")->implode(', '));

        $country = $this->option('country');

        if (blank($country)) {
            $this->newLine();
            $this->line('Tip: add --country=PH to exercise the POST path (CityList) as well.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Endpoint : '.$service->url('citylist').'  [POST]');

        $cities = $this->timed(
            fn (): array => $service->cities($country),
            'CityList',
        );

        if ($cities === null) {
            return self::FAILURE;
        }

        $this->line('Cities   : '.count($cities).' in '.strtoupper($country).' — e.g. '.collect($cities)->take(3)
            ->map(fn (array $c): string => "{$c['code']} {$c['name']}")->implode(', '));

        return self::SUCCESS;
    }

    /**
     * @param  callable(): array<int, array{code: string, name: string}>  $call
     * @return array<int, array{code: string, name: string}>|null null on failure
     */
    private function timed(callable $call, string $label): ?array
    {
        $start = microtime(true);

        try {
            $rows = $call();
        } catch (TboHotelException $e) {
            $ms = (int) round((microtime(true) - $start) * 1000);
            $this->error("{$label} FAILED after {$ms}ms: ".$e->getMessage());
            $this->newLine();
            $this->explain($e);

            return null;
        }

        $ms = (int) round((microtime(true) - $start) * 1000);
        $this->info("{$label} OK in {$ms}ms");

        return $rows;
    }

    private function explain(TboHotelException $e): void
    {
        match (true) {
            $e->isUnauthorized() => $this->line('The URL answered, so the host is right — the credentials are not. Check TBOHOTEL_*_USERNAME/_PASSWORD, and whether this server IP is whitelisted for the hotel contract (it is a separate agreement from TBO Air).'),
            $e->isTimeout() => $this->line('Nothing answered in time. Try the other candidate base URL — set TBOHOTEL_TEST_BASE_URL=http://api.tbotechnology.in/TBOHolidays_HotelAPI, which is what the live system uses.'),
            $e->isThrottled() => $this->line('Rate limited on the cheapest call there is — worth asking TBO what our QPS limit actually is.'),
            default => $this->line('Set TBOHOTEL_TEST_BASE_URL to try the other candidate, then re-run.'),
        };

        $this->line('The attempt was logged — inspect the request and response at /api-logs?supplier=tbohotel');
    }
}
