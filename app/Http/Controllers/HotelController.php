<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchHotelsRequest;
use App\Models\Hotel;
use App\Models\HotelCountry;
use App\Services\TboHotel\CatalogueSyncService;
use App\Services\TboHotel\DTO\PaxRoom;
use App\Services\TboHotel\DTO\SearchInput;
use App\Services\TboHotel\Exceptions\TboHotelException;
use App\Services\TboHotel\HotelSearchCache;
use App\Services\TboHotel\TboHotelService;
use App\Support\SupplierHtml;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;

class HotelController extends Controller
{
    public function index(): View
    {
        return view('hotels', [
            // TBO's own country list, not an ICU one: these are exactly the
            // nationality codes it accepts, and it is already synced locally.
            'countries' => HotelCountry::orderBy('name')->get(['code', 'name']),
        ]);
    }

    public function search(SearchHotelsRequest $request, TboHotelService $service, HotelSearchCache $cache): JsonResponse
    {
        $input = $request->searchInput();

        try {
            $payload = $cache->remember(
                $request->user()->id,
                $service->environment(),
                $input,
                fn () => $service->search($input),
            );
        } catch (TboHotelException $e) {
            return $this->supplierError($e);
        }

        return response()->json($payload + [
            'nights' => $input->nights(),
            'rooms' => $input->roomCount(),
            'guests' => $input->guests(),
        ]);
    }

    /**
     * Step 2 — one property's rooms, on their own page.
     *
     * A page rather than a panel on the results list, mirroring how a fare gets its own
     * page on the flight side. Choosing a room is a decision with policies and prices
     * to read, and it deserves the screen; it also makes the step addressable, so
     * going back to the results and forward again both work.
     *
     * Rates are re-fetched for this one hotel rather than carried over from the list.
     * One code is a single call, the prices are current, and the page survives a
     * reload — a results set held in a browser tab does none of those.
     */
    public function rooms(
        Request $request,
        string $code,
        TboHotelService $service,
        HotelSearchCache $cache,
        CatalogueSyncService $sync,
    ): View|RedirectResponse {
        $data = $request->validate([
            'checkIn' => ['required', 'date'],
            'checkOut' => ['required', 'date', 'after:checkIn'],
            'guestNationality' => ['required', 'string', 'size:2'],
            'rooms' => ['required', 'string', 'max:255'],
            // Where "back to results" goes, so the agent lands on the search they ran.
            'from' => ['nullable', 'string', 'max:32'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $hotel = Hotel::where('code', $code)->firstOrFail();
        $occupancy = HotelBookingController::decodeRooms($data['rooms']);

        $input = new SearchInput(
            checkIn: Carbon::parse($data['checkIn'])->toDateString(),
            checkOut: Carbon::parse($data['checkOut'])->toDateString(),
            rooms: array_map(fn (array $room): PaxRoom => PaxRoom::fromArray($room), $occupancy),
            guestNationality: strtoupper($data['guestNationality']),
            locationType: 'hotel',
            locationCode: $code,
        );

        try {
            $payload = $cache->remember(
                $request->user()->id,
                $service->environment(),
                $input,
                fn () => $service->search($input),
            );
        } catch (TboHotelException $e) {
            return $this->roomsError($e, $data);
        }

        // The panel used to enrich on open; this page is that panel now.
        if ($hotel->detailed_at === null) {
            try {
                $sync->enrich([$hotel->code]);
                $hotel->refresh();
            } catch (TboHotelException) {
                // Rates are what the agent came for.
            }
        }

        return view('hotels.rooms', [
            'hotel' => $hotel,
            'offer' => $payload['offers'][0] ?? null,
            'currency' => $payload['currency'] ?? '',
            // The Modify form is the shared partial, and its nationality select is
            // rendered server-side — see hotels/form.blade.php.
            'countries' => HotelCountry::orderBy('name')->get(['code', 'name']),
            'stay' => [
                'checkIn' => $input->checkIn,
                'checkOut' => $input->checkOut,
                'nights' => $input->nights(),
                'guestNationality' => $input->guestNationality,
                'rooms' => $occupancy,
                'roomsToken' => $data['rooms'],
                // What the Modify form is pre-filled with, and what the collapsed
                // summary reads before Alpine boots.
                'from' => $data['from'] ?? '',
                'label' => $data['label'] ?? '',
                'summary' => $this->staySummary($input, $data['label'] ?? ''),
            ],
            'backUrl' => $this->backToResults($data),
            // All of them, for the viewer — the grid shows four. Capped because TBO
            // returns well over a hundred for some properties and every one is a URL
            // inlined into the page.
            'images' => array_slice($hotel->images ?? [], 0, 60),
            'payableAtProperty' => $this->payableAtProperty($payload['offers'][0] ?? null),
            // Decided here so the tab strip and the page cannot disagree: a tab
            // pointing at a section that was not rendered is worse than no tab.
            'sections' => $this->sections($hotel, $payload['offers'][0] ?? null),
        ]);
    }

    /**
     * The jump links, in page order, limited to sections this property actually has.
     *
     * @param  array<string, mixed>|null  $offer
     * @return array<string, string>
     */
    private function sections(Hotel $hotel, ?array $offer): array
    {
        $hasRooms = $offer !== null && ! empty($offer['rooms']);

        return array_filter([
            'overview' => filled($hotel->description) ? 'Overview' : null,
            'rooms' => $hasRooms ? 'Rooms' : null,
            'facilities' => ! empty($hotel->facilities) ? 'Facilities' : null,
            'location' => filled($hotel->address) || $hotel->latitude
                || ! empty($hotel->attractions) || filled($hotel->phone)
                || filled($hotel->email) || filled($hotel->website)
                    ? 'Location'
                    : null,
            'policies' => filled($hotel->checkin_time) || filled($hotel->checkout_time)
                || $this->payableAtProperty($offer) !== []
                    ? 'Policies'
                    : null,
        ]);
    }

    /**
     * Every distinct charge the guest settles at the desk, across this property's rates.
     *
     * Deduplicated by description: the same deposit repeated once per rate is one fact
     * about the hotel, not four about the booking.
     *
     * @param  array<string, mixed>|null  $offer
     * @return array<int, string>
     */
    private function payableAtProperty(?array $offer): array
    {
        $descriptions = [];

        foreach ($offer['rooms'] ?? [] as $room) {
            foreach ($room['payableAtProperty'] ?? [] as $supplement) {
                $label = trim((string) ($supplement['description'] ?? ''));

                if ($label !== '') {
                    $descriptions[$label] = true;
                }
            }
        }

        return array_keys($descriptions);
    }

    /**
     * The one-line search summary above the stepper. Rendered server-side so it reads
     * correctly before Alpine boots, exactly as the flight wizard's does.
     */
    private function staySummary(SearchInput $input, string $label): string
    {
        $guests = $input->guests();
        $rooms = $input->roomCount();

        return sprintf(
            '%s · %s → %s · %d %s, %d %s',
            $label !== '' ? $label : 'Your search',
            Carbon::parse($input->checkIn)->format('j M Y'),
            Carbon::parse($input->checkOut)->format('j M Y'),
            $rooms,
            Str::plural('room', $rooms),
            $guests,
            Str::plural('guest', $guests),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function roomsError(TboHotelException $e, array $data): RedirectResponse
    {
        report($e);

        return redirect($this->backToResults($data))->with('error', $e->isExpired() || $e->isRateGone()
            ? 'Those prices have expired. Search again to see current availability.'
            : 'We could not reach the hotel provider. Please try again.');
    }

    /**
     * Back to the results, carrying the search so the agent lands on the list they
     * left rather than an empty form.
     *
     * @param  array<string, mixed>  $data
     */
    private function backToResults(array $data): string
    {
        if (blank($data['from'] ?? null)) {
            return route('hotels');
        }

        return route('hotels', [
            'checkIn' => $data['checkIn'],
            'checkOut' => $data['checkOut'],
            'guestNationality' => $data['guestNationality'],
            'rooms' => $data['rooms'],
            'city' => $data['from'],
            'label' => $data['label'] ?? '',
        ]);
    }

    /**
     * Everything we know about one property, for the panel behind a result card.
     *
     * Enriches on the spot when the hotel has never been detailed. The catalogue's
     * cheap pass gives a name, an address and a star rating — enough for a result
     * card and not enough for a page someone is deciding from — and enriching the
     * whole catalogue up front would be hours of crawling for hotels nobody opens.
     * One call, once, the first time anyone looks.
     */
    public function show(Request $request, string $code, CatalogueSyncService $sync): JsonResponse
    {
        $hotel = Hotel::where('code', $code)->firstOrFail();

        if ($hotel->detailed_at === null) {
            try {
                $sync->enrich([$hotel->code]);
                $hotel->refresh();
            } catch (TboHotelException) {
                // The card's data is still worth showing; a missing description is
                // not a reason to fail the panel.
            }
        }

        return response()->json([
            'code' => $hotel->code,
            'name' => $hotel->name,
            'address' => $hotel->address,
            'rating' => $hotel->rating,
            // TBO's descriptions are HTML. Cleaned here, on the way out, rather than
            // at sync time: the catalogue keeps what the supplier actually said, and
            // rows already stored are covered without a re-crawl.
            'description' => SupplierHtml::clean($hotel->description),
            'facilities' => $hotel->facilities ?? [],
            'attractions' => $hotel->attractions ?? [],
            'images' => array_slice($hotel->images ?? [], 0, 12),
            'checkInTime' => $hotel->checkin_time,
            'checkOutTime' => $hotel->checkout_time,
            'phone' => $hotel->phone,
            'latitude' => $hotel->latitude,
            'longitude' => $hotel->longitude,
        ]);
    }

    /**
     * Turn a supplier failure into something an agent can act on. The distinctions
     * matter: an expired search needs re-running, a throttle needs a moment, and a
     * timeout needs neither explained as the other.
     */
    private function supplierError(TboHotelException $e): JsonResponse
    {
        report($e);

        return match (true) {
            $e->isExpired(), $e->isRateGone() => response()->json([
                'message' => 'These prices have expired. Search again to see current availability.',
            ], 409),
            $e->isThrottled() => response()->json([
                'message' => 'Too many searches at once. Try again in a moment.',
            ], 429),
            $e->isTimeout() => response()->json([
                'message' => 'The hotel provider timed out. Try again, or narrow the search to fewer dates.',
            ], 504),
            default => response()->json([
                'message' => 'We could not reach the hotel provider. Please try again.',
            ], 502),
        };
    }
}
