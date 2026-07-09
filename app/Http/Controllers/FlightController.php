<?php

namespace App\Http\Controllers;

use App\Http\Requests\FareDetailRequest;
use App\Http\Requests\SearchFlightsRequest;
use App\Services\TboAir\Exceptions\TboAirException;
use App\Services\TboAir\FlightSearchCache;
use App\Services\TboAir\TboAirService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class FlightController extends Controller
{
    public function index(): View
    {
        return view('flights');
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
                    'currency' => $result['currency'],
                ];
            });
        } catch (TboAirException $e) {
            report($e);

            return response()->json([
                'message' => 'We could not reach the flight provider. Please try again.',
            ], 502);
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
            report($e);

            return response()->json([
                'message' => 'We could not price this fare. It may have expired — please search again.',
            ], 502);
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
            report($e);

            return response()->json([
                'message' => 'We could not load the fare rules. Please search again.',
            ], 502);
        }

        return response()->json($rule->toArray());
    }
}
