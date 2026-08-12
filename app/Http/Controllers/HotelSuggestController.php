<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\HotelCity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Location autocomplete for the hotel search form.
 *
 * Two kinds of answer, because TBO's Search takes hotel codes either way: pick a
 * city and we send its properties in chunks, or pick one property and we send only
 * that. The distinction is what the caller does with `type`.
 */
class HotelSuggestController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));

        if (mb_strlen($term) < 2) {
            return response()->json(['results' => []]);
        }

        // Cities first, and only enabled ones: offering a city we hold no hotels for
        // is offering a search that returns nothing.
        $cities = HotelCity::query()
            ->enabled()
            ->where('name', 'like', "{$term}%")
            ->orderByDesc('hotels_count')
            ->limit(6)
            ->get(['code', 'name', 'country_code', 'hotels_count'])
            ->map(fn (HotelCity $city): array => [
                'type' => 'city',
                'code' => $city->code,
                'label' => $city->name,
                'sublabel' => $city->country_code,
                'hotels' => $city->hotels_count,
            ]);

        $hotels = Hotel::query()
            ->where('name', 'like', "%{$term}%")
            ->orderByDesc('rating')
            ->orderBy('name')
            ->limit(6)
            ->get(['code', 'name', 'city_code', 'country_code', 'rating'])
            ->map(fn (Hotel $hotel): array => [
                'type' => 'hotel',
                'code' => $hotel->code,
                'label' => $hotel->name,
                'sublabel' => trim($hotel->city_code.' · '.$hotel->country_code, ' ·'),
                'rating' => $hotel->rating,
            ]);

        return response()->json(['results' => $cities->concat($hotels)->values()]);
    }
}
