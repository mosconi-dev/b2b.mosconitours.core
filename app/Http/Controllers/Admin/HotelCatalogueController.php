<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncHotelCatalogue;
use App\Models\Hotel;
use App\Models\HotelCity;
use App\Models\HotelCountry;
use App\Models\HotelSyncRun;
use App\Services\Rbac\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The hotel catalogue's control room: which cities we carry, and what the last
 * sync managed to do.
 *
 * It exists because TBO's Search needs hotel codes, so the catalogue is not a cache
 * we can rebuild on demand — it is a thing someone has to curate and keep current.
 */
class HotelCatalogueController extends Controller
{
    private const SCOPES = ['countries', 'cities', 'hotels', 'details'];

    /**
     * The city list as the current filters describe it.
     *
     * Shared by the page and by "carry everything matching", so the set an admin acts
     * on is by construction the set they were looking at. Two copies of this query
     * would eventually disagree, and the disagreement would be a country carried by
     * accident.
     *
     * @param  array{q: string, country: ?string, only: ?string}  $filters
     * @return Builder<HotelCity>
     */
    private function citiesMatching(array $filters)
    {
        return HotelCity::query()
            ->when($filters['country'], fn ($q) => $q->where('country_code', $filters['country']))
            ->when($filters['q'] !== '', fn ($q) => $q->where('name', 'like', "%{$filters['q']}%"))
            ->when($filters['only'] === 'enabled', fn ($q) => $q->enabled());
    }

    /**
     * @return array{q: string, country: ?string, only: ?string}
     */
    private function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->input('q')),
            'country' => strtoupper(trim((string) $request->input('country'))) ?: null,
            'only' => $request->input('only'), // enabled | null
        ];
    }

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $cities = $this->citiesMatching($filters)
            // Enabled first: the list is 194 rows for one country alone, and the
            // handful we carry is what anyone opening this page came to look at.
            ->orderByDesc('is_enabled')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.hotel-catalogue.index', [
            'cities' => $cities,
            // What "carry all matching" would actually touch, named on the button so
            // the number is read before it is pressed rather than after.
            'matching' => $cities->total(),
            'countries' => HotelCountry::orderBy('name')->get(['code', 'name']),
            'runs' => HotelSyncRun::with('user:id,name')->latest('id')->limit(10)->get(),
            'totals' => [
                'countries' => HotelCountry::count(),
                'cities' => HotelCity::count(),
                'enabled' => HotelCity::enabled()->count(),
                'hotels' => Hotel::count(),
                'detailed' => Hotel::whereNotNull('detailed_at')->count(),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * Carry, or stop carrying, many cities at once.
     *
     * Either the rows an admin ticked, or every city the current filter matches — the
     * second because selecting across eight pages of one country is how a catalogue
     * stays at two cities while everyone agrees it should not.
     *
     * Disabling in bulk leaves every hotel in place, exactly as the single toggle does.
     * The cities stop being searchable; nothing is thrown away, and a booking that
     * already references one of those properties still renders.
     */
    public function carryCities(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'carry' => ['required', 'boolean'],
            'all' => ['nullable', 'boolean'],
            'cities' => ['nullable', 'array'],
            'cities.*' => ['integer'],
        ]);

        $carry = (bool) $data['carry'];
        $filters = $this->filters($request);

        // "All matching" is resolved through the same query the page was drawn from,
        // so it can never mean more than what the admin was looking at.
        $query = ($data['all'] ?? false)
            ? $this->citiesMatching($filters)
            : HotelCity::whereIn('id', $data['cities'] ?? []);

        // Only the rows that would actually change, so the count reported back is the
        // number of cities affected rather than the number of rows in the filter.
        $changed = (clone $query)->where('is_enabled', ! $carry)->count();

        if ($changed === 0) {
            return back()->with('status', $carry
                ? 'Those cities were already carried.'
                : 'None of those cities were being carried.');
        }

        (clone $query)->where('is_enabled', ! $carry)->update(['is_enabled' => $carry]);

        $audit->log($carry ? 'tbohotel.cities_enabled' : 'tbohotel.cities_disabled', null, [
            'count' => $changed,
            'scope' => ($data['all'] ?? false) ? 'filter' : 'selection',
            'filters' => $filters,
        ]);

        $noun = $changed === 1 ? 'city is' : 'cities are';

        return back()->with('status', $carry
            ? "{$changed} {$noun} now carried. Run a hotel sync to pull their properties."
            : "{$changed} {$noun} no longer carried. Their hotels have been kept.");
    }

    /**
     * Carry this city, or stop carrying it.
     *
     * Disabling leaves the hotels in place. They may be referenced by a booking, and
     * re-enabling a city should not mean re-downloading it.
     */
    public function toggleCity(Request $request, HotelCity $city, AuditLogger $audit): RedirectResponse
    {
        $city->update(['is_enabled' => ! $city->is_enabled]);

        $audit->log($city->is_enabled ? 'tbohotel.city_enabled' : 'tbohotel.city_disabled', null, [
            'city' => $city->code,
            'name' => $city->name,
        ]);

        $verb = $city->is_enabled ? 'now carried' : 'no longer carried';

        return back()->with('status', "{$city->name} is {$verb}.".
            ($city->is_enabled && $city->hotels_count === 0 ? ' Run a hotel sync to pull its properties.' : ''));
    }

    public function sync(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'in:'.implode(',', self::SCOPES)],
            'target' => ['nullable', 'string', 'max:32'],
        ]);

        $scope = $validated['scope'];
        $target = $validated['target'] ?? null;

        if ($scope === 'cities' && blank($target)) {
            return back()->with('error', 'Choose a country before syncing its cities.');
        }

        if ($scope === 'hotels' && blank($target) && HotelCity::query()->enabled()->doesntExist()) {
            return back()->with('error', 'No cities are enabled yet, so there is nothing to pull.');
        }

        SyncHotelCatalogue::dispatch($scope, $target, $request->user()->id);

        $audit->log('tbohotel.catalogue_synced', null, ['scope' => $scope, 'target' => $target]);

        return back()->with('status', "Queued a {$scope} sync. It runs in the background — reload for its result.");
    }
}
