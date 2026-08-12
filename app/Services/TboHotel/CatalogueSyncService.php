<?php

namespace App\Services\TboHotel;

use App\Models\Hotel;
use App\Models\HotelCity;
use App\Models\HotelCountry;
use App\Models\HotelSyncRun;
use App\Models\User;
use App\Services\TboHotel\Exceptions\TboHotelException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Keeps the local hotel catalogue in step with TBO's.
 *
 * Three properties matter, and the system live today has none of them:
 *
 * - **Upsert, never insert-if-missing.** Production skips any row whose code it
 *   already holds, so a renamed city keeps its old name for ever.
 * - **Never abort the run on one failure.** Production returns a 422 on the first
 *   failed city, losing everything after it with no record of where it stopped.
 *   Here every failure is recorded and the run continues.
 * - **Actually sync hotels.** Nothing in production ever writes the hotel table at
 *   all; it was loaded by hand once and has no refresh path.
 */
class CatalogueSyncService
{
    public const SOURCE = 'tbo';

    /** Hotels per HotelDetails call. 100 works (970 KB, 2.6 s); 50 keeps it modest. */
    public const DETAIL_BATCH = 50;

    public function __construct(private readonly TboHotelService $tbo) {}

    /**
     * Every country TBO sells in. Small, cheap, and what city sync iterates.
     */
    public function syncCountries(?User $actor = null): HotelSyncRun
    {
        return $this->run('countries', null, $actor, function (HotelSyncRun $run): void {
            $rows = $this->tbo->countries();

            $this->upsert(HotelCountry::class, array_map(fn (array $c): array => [
                'source' => self::SOURCE,
                'code' => strtoupper($c['code']),
                'name' => $c['name'],
            ], $rows), ['name']);

            $run->processed = count($rows);
        });
    }

    /**
     * Every city in one country.
     *
     * `is_enabled` is deliberately absent from the update list: re-syncing must not
     * silently switch off the cities an admin turned on.
     */
    public function syncCities(string $countryCode, ?User $actor = null): HotelSyncRun
    {
        $countryCode = strtoupper(trim($countryCode));

        return $this->run('cities', $countryCode, $actor, function (HotelSyncRun $run) use ($countryCode): void {
            $rows = $this->tbo->cities($countryCode);

            $this->upsert(HotelCity::class, array_map(fn (array $c): array => [
                'source' => self::SOURCE,
                'code' => $c['code'],
                'country_code' => $countryCode,
                'name' => $c['name'],
            ], $rows), ['name', 'country_code']);

            $run->processed = count($rows);
        });
    }

    /**
     * Every hotel in one city.
     *
     * Hotels that vanish from TBO's list are left in place rather than deleted: a
     * booking may reference one, and a hotel absent from today's response is more
     * often a supplier hiccup than a demolished building.
     */
    public function syncHotels(string $cityCode, ?User $actor = null): HotelSyncRun
    {
        return $this->run('hotels', $cityCode, $actor, function (HotelSyncRun $run) use ($cityCode): void {
            $run->processed = $this->pullCity($cityCode);
        });
    }

    /**
     * Every hotel in every enabled city, one run covering all of them.
     *
     * @param  array<int, string>|null  $cityCodes  restrict to these, else all enabled
     */
    public function syncEnabledCities(?array $cityCodes = null, ?User $actor = null): HotelSyncRun
    {
        return $this->run('hotels', 'enabled', $actor, function (HotelSyncRun $run) use ($cityCodes): void {
            $cities = HotelCity::query()
                ->enabled()
                ->when($cityCodes !== null, fn ($q) => $q->whereIn('code', $cityCodes))
                ->orderBy('code')
                ->get();

            foreach ($cities as $city) {
                try {
                    $this->pullCity($city->code);
                    $run->processed++;
                } catch (TboHotelException|Throwable $e) {
                    // One unreachable city must not cost us the other ninety-three.
                    $run->recordFailure($city->code, $city->name, $e->getMessage());
                }
            }
        });
    }

    /**
     * Fill in descriptions, images and facilities for hotels that have none.
     *
     * A separate pass because it costs one batched call per ~50 hotels: enriching
     * every Philippine city up front would be tens of minutes of crawling before
     * anything worked, when a search result needs none of it.
     */
    public function syncDetails(?string $cityCode = null, int $limit = 500, ?User $actor = null): HotelSyncRun
    {
        return $this->run('details', $cityCode ?? 'all', $actor, function (HotelSyncRun $run) use ($cityCode, $limit): void {
            Hotel::query()
                ->needingDetail()
                ->when($cityCode !== null, fn ($q) => $q->where('city_code', $cityCode))
                ->orderBy('id')
                ->limit($limit)
                ->pluck('code')
                ->chunk(self::DETAIL_BATCH)
                ->each(function ($codes) use ($run): void {
                    $codes = $codes->values()->all();

                    try {
                        $details = $this->tbo->details($codes);
                    } catch (TboHotelException|Throwable $e) {
                        $run->recordFailure(implode(',', array_slice($codes, 0, 3)).'…', null, $e->getMessage());

                        return;
                    }

                    $now = Carbon::now();

                    foreach ($codes as $code) {
                        // A code TBO did not return is stamped anyway. Without that the
                        // next run asks for the same missing hotel for ever, and the
                        // pass never reaches the end of the queue.
                        Hotel::query()
                            ->where('source', self::SOURCE)
                            ->where('code', $code)
                            ->update(($details[$code] ?? []) + ['detailed_at' => $now]);

                        $run->processed++;
                    }
                });
        });
    }

    /**
     * Pull and store one city's hotels. Returns how many were written.
     */
    private function pullCity(string $cityCode): int
    {
        $hotels = $this->tbo->hotels($cityCode);
        $now = Carbon::now();

        $this->upsert(Hotel::class, array_map(fn (array $h): array => $h + [
            'source' => self::SOURCE,
            'synced_at' => $now,
        ], $hotels), ['city_code', 'country_code', 'name', 'address', 'rating', 'latitude', 'longitude', 'synced_at']);

        HotelCity::query()
            ->where('source', self::SOURCE)
            ->where('code', $cityCode)
            ->update(['hotels_count' => count($hotels), 'hotels_synced_at' => $now]);

        return count($hotels);
    }

    /**
     * Chunked upsert on (source, code).
     *
     * @param  class-string<Model>  $model
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $update  columns a re-sync is allowed to overwrite
     */
    private function upsert(string $model, array $rows, array $update): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $model::upsert($chunk, ['source', 'code'], $update);
        }
    }

    /**
     * Wrap one operation in a run record, so a sync that half-worked says so.
     *
     * @param  callable(HotelSyncRun): void  $work
     */
    private function run(string $scope, ?string $target, ?User $actor, callable $work): HotelSyncRun
    {
        $run = HotelSyncRun::create([
            'scope' => $scope,
            'target' => $target,
            'status' => HotelSyncRun::RUNNING,
            'user_id' => $actor?->getKey(),
            'started_at' => Carbon::now(),
        ]);

        try {
            $work($run);
            $run->status = HotelSyncRun::COMPLETED;
        } catch (Throwable $e) {
            $run->status = HotelSyncRun::FAILED;
            $run->message = $e->getMessage();
        }

        $run->finished_at = Carbon::now();
        $run->save();

        return $run;
    }
}
