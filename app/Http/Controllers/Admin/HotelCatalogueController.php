<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncHotelCatalogue;
use App\Models\Hotel;
use App\Models\HotelCity;
use App\Models\HotelCountry;
use App\Models\HotelSyncRun;
use App\Services\Rbac\AuditLogger;
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

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $country = strtoupper(trim((string) $request->query('country'))) ?: null;
        $only = $request->query('only'); // enabled | null

        $cities = HotelCity::query()
            ->when($country, fn ($q) => $q->where('country_code', $country))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($only === 'enabled', fn ($q) => $q->enabled())
            // Enabled first: the list is 194 rows for one country alone, and the
            // handful we carry is what anyone opening this page came to look at.
            ->orderByDesc('is_enabled')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.hotel-catalogue.index', [
            'cities' => $cities,
            'countries' => HotelCountry::orderBy('name')->get(['code', 'name']),
            'runs' => HotelSyncRun::with('user:id,name')->latest('id')->limit(10)->get(),
            'totals' => [
                'countries' => HotelCountry::count(),
                'cities' => HotelCity::count(),
                'enabled' => HotelCity::enabled()->count(),
                'hotels' => Hotel::count(),
                'detailed' => Hotel::whereNotNull('detailed_at')->count(),
            ],
            'filters' => ['q' => $search, 'country' => $country, 'only' => $only],
        ]);
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
