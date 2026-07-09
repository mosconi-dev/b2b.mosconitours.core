<?php

namespace App\Http\Controllers;

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
}
