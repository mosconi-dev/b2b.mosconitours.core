<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHotelBookingRequest;
use App\Models\Hotel;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLoadRequest;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\TboHotel\Exceptions\TboHotelException;
use App\Services\TboHotel\HotelBookingService;
use App\Services\TboHotel\TboHotelService;
use App\Support\Countries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The hotel booking wizard: steps 3–5.
 *
 * Steps 1 and 2 — choosing the hotel, then the room — happen on the results page,
 * exactly as choosing a flight does. Arriving here means a rate has been picked, so the
 * first thing this does is ask TBO whether it is still real and at what price.
 */
class HotelBookingController extends Controller
{
    /**
     * Open the wizard on a chosen rate.
     *
     * PreBook runs server-side before anything renders: §18 makes its policy and price
     * final, so the terms the agent is about to accept must be the supplier's current
     * ones, not the ones cached on a results page from ten minutes ago.
     */
    public function create(Request $request, TboHotelService $service): View|RedirectResponse
    {
        $data = $request->validate([
            'bookingCode' => ['required', 'string', 'max:8192'],
            'checkIn' => ['required', 'date'],
            'checkOut' => ['required', 'date', 'after:checkIn'],
            'locationCode' => ['required', 'string', 'max:32'],
            'guestNationality' => ['required', 'string', 'size:2'],
            'rooms' => ['required', 'string', 'max:255'], // "2-0;2-1x8" — see rooms()
            'shownFare' => ['nullable', 'numeric', 'min:0'],
            // The city behind the search, so "back" reaches the rooms page rather than
            // an empty form.
            'from' => ['nullable', 'string', 'max:32'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $quote = $service->preBook($data['bookingCode']);
        } catch (TboHotelException $e) {
            report($e);

            return redirect()->route('hotels')->with('error', $e->isExpired() || $e->isRateGone()
                ? 'That rate has expired. Search again to see current availability.'
                : 'We could not confirm that rate with the hotel. Please try again.');
        }

        $hotel = Hotel::where('code', $quote->hotelCode ?: $data['locationCode'])->first();
        $rooms = self::decodeRooms($data['rooms']);
        $shownFare = isset($data['shownFare']) ? (float) $data['shownFare'] : null;

        return view('hotels.book', [
            'quote' => $quote->toArray(),
            'bookingCode' => $quote->room->bookingCode,
            'hotel' => $hotel,
            'stay' => [
                'checkIn' => $data['checkIn'],
                'checkOut' => $data['checkOut'],
                'locationCode' => $data['locationCode'],
                'guestNationality' => strtoupper($data['guestNationality']),
                'nationalityName' => Countries::name($data['guestNationality']),
                'rooms' => $rooms,
            ],
            'shownFare' => $shownFare,
            // One step back is the room list for this property, not the results — the
            // agent has already chosen the hotel, and a rate is what they are changing.
            'backUrl' => route('hotels.rooms', [
                'code' => $hotel?->code ?? $data['locationCode'],
                'checkIn' => $data['checkIn'],
                'checkOut' => $data['checkOut'],
                'guestNationality' => strtoupper($data['guestNationality']),
                'rooms' => $data['rooms'],
                'from' => $data['from'] ?? '',
                'label' => $data['label'] ?? '',
            ]),
            // Whether to open on the price gate. Computed here rather than in the
            // browser so the figure the agent is asked to accept is the supplier's.
            'priceChanged' => $shownFare !== null && $quote->priceChanged($shownFare),
            'wallet' => $this->walletSummary($request->user()),
            'walletRequestUrl' => $request->user()->can('create', WalletLoadRequest::class)
                ? route('wallet.requests.create')
                : null,
        ]);
    }

    /**
     * Commit the booking on our side: a `quoted` row, the wallet debited, nothing sent
     * to TBO yet. Vouchering is Phase 5.
     */
    public function store(StoreHotelBookingRequest $request, HotelBookingService $bookings): RedirectResponse|JsonResponse
    {
        try {
            $booking = $bookings->createFromQuote(
                $request->user(),
                $request->searchInput(),
                $request->validated()['bookingCode'],
                $request->guests(),
                $request->contact(),
                $request->shownFare(),
                $request->acceptsPriceChange(),
            );
        } catch (BookingException $e) {
            return $this->storeError($request, $e->getMessage(), 422);
        } catch (TboHotelException $e) {
            report($e);

            return $e->isExpired() || $e->isRateGone()
                ? $this->storeError($request, 'That rate has expired. Please search again.', 409)
                : $this->storeError($request, 'We could not confirm this rate with the hotel. Please try again.', 502);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'redirect' => route('bookings.show', $booking),
                'reference' => $booking->reference,
            ]);
        }

        return redirect()
            ->route('bookings.show', $booking)
            ->with('status', "Booking {$booking->reference} created.");
    }

    private function storeError(Request $request, string $message, int $status): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message], $status)
            : back()->withInput()->with('error', $message);
    }

    /**
     * Occupancy, round-tripped through the URL.
     *
     * `"2-0;2-1x8,10"` is two rooms: two adults, then two adults with children aged 8
     * and 10. Compact because it rides in a query string beside a BookingCode that is
     * already long, and lossless because the guest form has to offer exactly the right
     * number of name fields.
     *
     * Public and static because the same token travels through step 2 as well, and two
     * parsers for one format is one parser too many.
     *
     * @return array<int, array{adults: int, children: int, childrenAges: array<int, int>}>
     */
    public static function decodeRooms(string $encoded): array
    {
        $rooms = [];

        foreach (explode(';', $encoded) as $part) {
            if (trim($part) === '') {
                continue;
            }

            [$adults, $rest] = array_pad(explode('-', $part, 2), 2, '0');
            [$children, $ages] = array_pad(explode('x', (string) $rest, 2), 2, '');

            $parsed = array_values(array_filter(
                array_map('intval', array_filter(explode(',', (string) $ages), 'strlen')),
                fn (int $age): bool => $age >= 0 && $age <= 18,
            ));

            $rooms[] = [
                'adults' => max(1, min(8, (int) $adults)),
                // The ages are the truth: a count that disagrees with them would build
                // a form with the wrong number of fields.
                'children' => count($parsed) ?: max(0, min(4, (int) $children)),
                'childrenAges' => $parsed,
            ];
        }

        return $rooms === [] ? [['adults' => 2, 'children' => 0, 'childrenAges' => []]] : $rooms;
    }

    /**
     * @return array{balance: string, currency: string}|null
     */
    private function walletSummary(User $user): ?array
    {
        if ($user->agency_id === null) {
            return null;
        }

        $wallet = Wallet::where('agency_id', $user->agency_id)->first(['currency', 'balance']);

        return [
            'balance' => (string) ($wallet?->balance ?? '0.00'),
            'currency' => $wallet?->currency ?? 'PHP',
        ];
    }
}
