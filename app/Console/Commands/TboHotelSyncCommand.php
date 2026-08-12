<?php

namespace App\Console\Commands;

use App\Models\HotelCity;
use App\Models\HotelSyncRun;
use App\Services\TboHotel\CatalogueSyncService;
use Illuminate\Console\Command;

/**
 * Refreshes the local hotel catalogue.
 *
 *   tbohotel:sync countries
 *   tbohotel:sync cities --country=PH
 *   tbohotel:sync hotels --city=127343
 *   tbohotel:sync hotels --enabled
 *   tbohotel:sync details --city=127343 --limit=500
 *
 * Scopes are separate rather than one "sync everything" because they cost wildly
 * different amounts: countries is one call, hotels is one per city, and details is
 * one per fifty hotels. Running the cheap ones should not mean waiting for the
 * expensive one.
 */
class TboHotelSyncCommand extends Command
{
    protected $signature = 'tbohotel:sync
        {scope : countries|cities|hotels|details}
        {--country= : ISO country code, for the cities scope}
        {--city= : TBO city code, for the hotels and details scopes}
        {--enabled : Every enabled city, for the hotels scope}
        {--limit=500 : Hotels to enrich, for the details scope}';

    protected $description = 'Sync the local TBO hotel catalogue (countries, cities, hotels, details).';

    public function handle(CatalogueSyncService $sync): int
    {
        $run = match ($this->argument('scope')) {
            'countries' => $sync->syncCountries(),
            'cities' => $this->cities($sync),
            'hotels' => $this->hotels($sync),
            'details' => $sync->syncDetails($this->option('city'), (int) $this->option('limit')),
            default => null,
        };

        if ($run === null) {
            $this->error('Unknown scope. Use one of: countries, cities, hotels, details.');

            return self::FAILURE;
        }

        return $this->report($run);
    }

    private function cities(CatalogueSyncService $sync): ?HotelSyncRun
    {
        $country = $this->option('country');

        if (blank($country)) {
            $this->error('The cities scope needs --country=XX (an ISO 3166-1 alpha-2 code).');

            return null;
        }

        return $sync->syncCities($country);
    }

    private function hotels(CatalogueSyncService $sync): ?HotelSyncRun
    {
        $city = $this->option('city');

        if (filled($city)) {
            return $sync->syncHotels($city);
        }

        if (! $this->option('enabled')) {
            $this->error('The hotels scope needs --city=<code> or --enabled.');

            return null;
        }

        if (HotelCity::query()->enabled()->doesntExist()) {
            $this->warn('No cities are enabled, so there is nothing to pull.');
            $this->line('Enable them in Admin → Settings → TBO Hotel, or sync one directly with --city=<code>.');

            return null;
        }

        return $sync->syncEnabledCities();
    }

    private function report(HotelSyncRun $run): int
    {
        $seconds = $run->durationSeconds();
        $target = $run->target ? " [{$run->target}]" : '';

        if ($run->status === HotelSyncRun::FAILED) {
            $this->error("{$run->scope}{$target} FAILED after {$seconds}s: {$run->message}");

            return self::FAILURE;
        }

        $this->info("{$run->scope}{$target} OK in {$seconds}s — {$run->processed} processed");

        if ($run->failed === 0) {
            return self::SUCCESS;
        }

        // A partial run is reported as a failure on purpose: an exit code of 0 on a
        // sync that skipped a third of its cities is how gaps go unnoticed.
        $this->newLine();
        $this->warn("{$run->failed} could not be fetched:");

        foreach (array_slice($run->failures ?? [], 0, 10) as $failure) {
            $this->line('  '.($failure['label'] ?? $failure['target']).' — '.$failure['reason']);
        }

        if ($run->failed > 10) {
            $this->line('  … and '.($run->failed - 10).' more (see hotel_sync_runs #'.$run->id.')');
        }

        $this->newLine();
        $this->line('Re-run the same command to pick these up — everything is upserted.');

        return self::FAILURE;
    }
}
