<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\TboHotel\CatalogueSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs a catalogue sync off the request.
 *
 * Pulling every enabled city is minutes of HTTP, which is not something a browser
 * should be made to wait for. The run record is the progress report: the admin page
 * reads it rather than the response.
 */
class SyncHotelCatalogue implements ShouldQueue
{
    use Queueable;

    /** Long, because the work is one HTTP call per city and TBO is not always quick. */
    public int $timeout = 1800;

    /**
     * Failures are recorded per city inside the service, so a retry would repeat
     * work that already succeeded rather than fix anything. Re-running the sync is
     * an explicit act.
     */
    public int $tries = 1;

    public function __construct(
        private readonly string $scope,
        private readonly ?string $target = null,
        private readonly ?int $userId = null,
    ) {}

    public function handle(CatalogueSyncService $sync): void
    {
        $actor = $this->userId ? User::find($this->userId) : null;

        match ($this->scope) {
            'countries' => $sync->syncCountries($actor),
            'cities' => $sync->syncCities((string) $this->target, $actor),
            'hotels' => $this->target === null || $this->target === 'enabled'
                ? $sync->syncEnabledCities(null, $actor)
                : $sync->syncHotels($this->target, $actor),
            'details' => $sync->syncDetails($this->target, 2000, $actor),
            default => null,
        };
    }
}
