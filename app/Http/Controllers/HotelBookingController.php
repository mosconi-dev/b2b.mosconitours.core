<?php

namespace App\Http\Controllers;

use App\Enums\BookingProduct;
use App\Enums\BookingStatus;
use App\Http\Requests\StoreHotelBookingRequest;
use App\Jobs\BookHotelJob;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLoadRequest;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\Booking\HotelVoucher;
use App\Services\Pricing\Money;
use App\Services\Pricing\OfferPricer;
use App\Services\TboHotel\Exceptions\TboHotelException;
use App\Services\TboHotel\HotelBookingService;
use App\Services\TboHotel\TboHotelService;
use App\Support\Countries;
use App\Support\TravelScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
    public function create(Request $request, TboHotelService $service, OfferPricer $pricer): View|RedirectResponse
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

        $nights = max(1, (int) Carbon::parse($data['checkIn'])->diffInDays(Carbon::parse($data['checkOut'])));

        // Priced through the same path as the rooms page, so `totalFare` on this screen
        // is a selling price like the one the agent clicked.
        $priced = $pricer->preBookQuote(
            $quote->toArray(),
            [
                'hotelCode' => $quote->hotelCode ?: $data['locationCode'],
                'countryCode' => $hotel?->country_code,
                'cityCode' => $hotel?->city_code,
                'rating' => $hotel?->rating,
                'scope' => TravelScopeResolver::forCountryCode($hotel?->country_code)->value,
            ],
            $request->user(),
            $nights,
            count($rooms),
            $data['checkIn'],
        );

        return view('hotels.book', [
            'quote' => $priced,
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
            // Finishing the wizard now takes the room, so the Payment step has to say
            // what that means here. Read from the supplier: it is the same answer that
            // gets stamped on the booking and later refuses a cross-environment Book.
            'isLive' => $service->environment() === 'live',
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
            // Whether to open on the price gate. SELL against SELL — both sides have
            // been through the engine, so this fires only when TBO actually moved and
            // never merely because a markup was applied.
            'priceChanged' => $shownFare !== null
                && Money::of($shownFare)->compare(Money::of($priced['totalFare'] ?? 0)) !== 0,
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

        // Finishing the wizard is the whole transaction, as it is for flights: the Book
        // goes out now and there is nothing further for the agent to press.
        $this->queueBook($booking);

        if ($request->expectsJson()) {
            return response()->json([
                'redirect' => route('bookings.show', $booking),
                'reference' => $booking->reference,
            ]);
        }

        return redirect()
            ->route('bookings.show', $booking)
            ->with('status', "Booking {$booking->reference} created — confirming with the hotel now.");
    }

    /**
     * Mark the booking as being worked on, then hand it to the queue.
     *
     * The status moves first and in the same breath as the dispatch, so the page never
     * shows a booking as merely `quoted` — and offers a button to send it — while a job
     * is already on its way to taking the room. What stops the two paths colliding is
     * `book_sent_at`, not the status.
     */
    private function queueBook(Booking $booking): void
    {
        if ($booking->status === BookingStatus::Quoted) {
            $booking->update(['status' => BookingStatus::Processing]);
        }

        BookHotelJob::dispatch($booking->getKey());
    }

    /**
     * Resume a booking the queue never sent.
     *
     * Not the normal path and not a second purchase: finishing the wizard sends the
     * Book. This is the recovery path for the one state that genuinely strands — the
     * agency charged, the stay saved, and the job that should have taken the room lost
     * to a dead worker before it ran. `quoted` is the only state that describes that,
     * and `book_sent_at` stops it being used for anything already on the wire.
     */
    public function book(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless(
            $booking->user_id === $request->user()->id && $booking->isVisibleTo($request->user()),
            403,
        );

        abort_unless($booking->product === BookingProduct::Hotel, 404);

        if ($booking->status !== BookingStatus::Quoted || $booking->hotel?->book_sent_at !== null) {
            return back()->with('error', "Booking {$booking->reference} is {$booking->status->value} and cannot be sent again.");
        }

        $this->queueBook($booking);

        return back()->with('status', "Confirming {$booking->reference} with the hotel now.");
    }

    /**
     * Release the room.
     *
     * Synchronous, like the refresh and unlike the Book: Cancel answers in about a
     * second, and an agent who has just been shown what it will cost is entitled to
     * find out on this page whether it worked.
     */
    public function cancel(Request $request, Booking $booking, HotelBookingService $bookings): RedirectResponse
    {
        abort_unless(
            $booking->user_id === $request->user()->id && $booking->isVisibleTo($request->user()),
            403,
        );

        abort_unless($booking->product === BookingProduct::Hotel, 404);

        try {
            $booking = $bookings->cancel($booking);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        } catch (TboHotelException $e) {
            report($e);

            return back()->with('error', 'We could not reach the hotel provider. Please try again in a moment.');
        }

        if ($booking->status === BookingStatus::Cancelling) {
            return back()->with('status',
                "The hotel provider has not confirmed the cancellation of {$booking->reference} yet. We are checking, and the page will show the outcome."
            );
        }

        $charge = (float) ($booking->hotel?->cancellation_charge ?? 0);

        return back()->with('status', $charge > 0
            ? "Booking {$booking->reference} cancelled. An estimated cancellation charge of {$booking->currency} ".number_format($charge, 2).' has been applied.'
            : "Booking {$booking->reference} cancelled at no charge.");
    }

    /**
     * Ask TBO what this booking is now.
     *
     * Synchronous on purpose, unlike the Book: BookingDetail is a read that answers in
     * about a second, and an agent pressing it is asking a question they want answered
     * on this page rather than eventually.
     */
    public function refresh(Request $request, Booking $booking, HotelBookingService $bookings): RedirectResponse
    {
        abort_unless(
            $booking->user_id === $request->user()->id && $booking->isVisibleTo($request->user()),
            403,
        );

        abort_unless($booking->product === BookingProduct::Hotel, 404);

        try {
            $booking = $bookings->refresh($booking);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        } catch (TboHotelException $e) {
            report($e);

            return back()->with('error', 'We could not reach the hotel provider. Please try again in a moment.');
        }

        $reported = $booking->hotel?->supplier_status;

        return back()->with('status', filled($reported)
            ? "The hotel provider reports this booking as {$reported}."
            : 'The hotel provider did not recognise this booking — contact support.');
    }

    /**
     * The printable voucher — what a guest hands over at the desk.
     *
     * Rendered entirely from hotel_bookings, so it prints during a TBO outage and reads
     * the same years later whatever has happened to the property since. There is
     * nothing to print before TBO has confirmed: until then it is a quote.
     *
     * `?prices=0` renders the guest copy — the same document without the rate, which is
     * what an agency hands the traveller. Same switch the e-ticket carries.
     */
    public function voucher(Request $request, Booking $booking): View
    {
        abort_unless(
            $booking->user_id === $request->user()->id && $booking->isVisibleTo($request->user()),
            403,
        );

        abort_unless($booking->product === BookingProduct::Hotel, 404);
        abort_if(blank($booking->hotel?->confirmation_number), 404);

        return view('hotels.voucher', [
            'booking' => $booking,
            'stay' => $booking->hotel,
            'voucher' => HotelVoucher::for($booking, withPrices: $request->query('prices') !== '0'),
        ]);
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
