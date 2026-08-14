<?php

namespace App\Http\Controllers;

use App\Enums\BookingProduct;
use App\Http\Requests\FareDetailRequest;
use App\Http\Requests\SearchFlightsRequest;
use App\Http\Requests\StoreRecentFlightSearchesRequest;
use App\Models\Booking;
use App\Services\Search\FlightRecentSearches;
use App\Services\TboAir\Exceptions\TboAirException;
use App\Services\TboAir\FlightSearchCache;
use App\Services\TboAir\TboAirService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class FlightController extends Controller
{
    public function index(Request $request, FlightRecentSearches $recent): View
    {
        return view('flights', [
            'recent' => $this->upcoming($recent->get($request->user()->id)),
            'recentBookings' => Booking::recentFor($request->user(), BookingProduct::Flight),
        ]);
    }

    /**
     * Drop the searches whose departure has already been and gone.
     *
     * A search needs a departure of today or later, so an entry that has aged past its
     * own dates can only be refused — and it fails silently rather than loudly: the date
     * picker will not display a date below its minimum, so restoring one hands back a
     * form with an empty departure field and no word about why.
     *
     * Filtered on the way out rather than rejected on the way in, because the list is
     * written as a whole and one leg that expired since should not cost the agent the
     * five beside it that are still good.
     *
     * @param  array<int, array<string, mixed>>  $recent
     * @return array<int, array<string, mixed>>
     */
    private function upcoming(array $recent): array
    {
        $today = Carbon::today()->toDateString();

        return array_values(array_filter($recent, function (array $entry) use ($today): bool {
            foreach ($entry['segments'] ?? [] as $segment) {
                // ISO dates, so string order is date order. Every leg, not just the
                // first: a multi-city trip is only bookable if all of it still lies ahead.
                if (($segment['departure'] ?? '') < $today) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Persist the user's recent-search shortcuts (cached, per-user, ~1 day). The
     * client owns the list shape (dedup/order/cap) and pushes the whole array on
     * each change; we validate and store it.
     */
    public function recent(StoreRecentFlightSearchesRequest $request, FlightRecentSearches $store): Response
    {
        $store->put($request->user()->id, $request->validated()['recent']);

        return response()->noContent();
    }

    public function search(SearchFlightsRequest $request, TboAirService $service, FlightSearchCache $cache): JsonResponse
    {
        $input = $request->searchInput();

        try {
            // Cached per-user for a few minutes so refreshes / repeat searches
            // don't re-hit the provider. A failure throws out of remember() and
            // is therefore never cached.
            $payload = $cache->remember($request->user()->id, $service->environment(), $input, function () use ($service, $input): array {
                $result = $service->search($input);

                return [
                    'results' => array_map(fn ($offer) => $offer->toArray(), $result['offers']),
                    'traceId' => $result['traceId'],
                    'resultType' => $result['resultType'],
                    'currency' => $result['currency'],
                ];
            });
        } catch (TboAirException $e) {
            return $this->providerError($e, 'We could not reach the flight provider. Please try again.');
        }

        return response()->json($payload);
    }

    /**
     * Re-price a selected result (FareQuote) before any commitment. Not cached —
     * the fare is binding and time-sensitive within the TraceId window.
     */
    public function fareQuote(FareDetailRequest $request, TboAirService $service): JsonResponse
    {
        try {
            $quote = $service->fareQuote($request->selection());
        } catch (TboAirException $e) {
            return $this->providerError($e, 'We could not price this fare. It may have expired — please search again.');
        }

        return response()->json($quote->toArray());
    }

    /**
     * Fare rules / cancellation policy for a selected result (FareRule).
     */
    public function fareRule(FareDetailRequest $request, TboAirService $service): JsonResponse
    {
        try {
            $rule = $service->fareRule($request->selection());
        } catch (TboAirException $e) {
            return $this->providerError($e, 'We could not load the fare rules. Please search again.');
        }

        return response()->json($rule->toArray());
    }

    /**
     * Available ancillaries (baggage / meals) for a selected result (GetSSR).
     */
    public function ssr(FareDetailRequest $request, TboAirService $service): JsonResponse
    {
        try {
            $ssr = $service->ssr($request->selection());
        } catch (TboAirException $e) {
            return $this->providerError($e, 'We could not load add-ons for this fare.');
        }

        return response()->json($ssr->toArray());
    }

    /**
     * Map a provider failure to a JSON error. A gateway timeout (common for routes
     * the current environment doesn't serve) gets a clearer message + a 504 status;
     * anything else falls back to the action-specific message + 502.
     */
    private function providerError(TboAirException $e, string $fallback): JsonResponse
    {
        report($e);

        if ($e->isTimeout()) {
            return response()->json([
                'message' => 'The flight provider timed out for this request. Please try again in a moment, or try a different route or date.',
            ], 504);
        }

        return response()->json(['message' => $fallback], 502);
    }
}
