<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Services\Booking\BookingService;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\TboAir\DTO\SelectionInput;
use App\Services\TboAir\Exceptions\TboAirException;
use App\Services\TboAir\TboAirService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function index(Request $request): View
    {
        $bookings = Booking::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return view('bookings.index', compact('bookings'));
    }

    public function show(Request $request, Booking $booking): View
    {
        abort_unless($booking->user_id === $request->user()->id, 403);

        return view('bookings.show', compact('booking'));
    }

    /**
     * The multi-step booking wizard (Select Flight → Guest Details → Add-ons →
     * Payment → Confirmation). Re-fetches the binding fare (+ SSR for LCC) server-side
     * to render the flight/price and add-on options; sends the user back to search if
     * the fare has expired.
     */
    public function create(Request $request, TboAirService $service): View|RedirectResponse
    {
        $data = $request->validate([
            'traceId' => ['required', 'string', 'max:255'],
            'resultIndex' => ['required', 'string', 'max:8192'],
            'oldFare' => ['nullable', 'numeric'], // the searched fare, for the price-change diff
        ]);

        $selection = new SelectionInput($data['traceId'], $data['resultIndex']);

        try {
            $quote = $service->fareQuote($selection);
            $ssr = $quote->isLcc ? $service->ssr($selection) : null;
        } catch (TboAirException $e) {
            report($e);

            return redirect()->route('flights')->with('status', $e->isTimeout()
                ? 'The flight provider timed out. Please search again.'
                : 'That fare is no longer available — please search again.');
        }

        return view('bookings.create', [
            'traceId' => $selection->traceId,
            'resultIndex' => $selection->resultIndex,
            'quote' => $quote->toArray(),
            'ssr' => $ssr?->toArray(),
            'oldFare' => (float) ($data['oldFare'] ?? 0),
            'search' => (string) $request->query('search', ''),
            // `edit=1` tells the flights page to reopen the search form (not just the
            // collapsed results), matching the in-page "Edit search" button.
            'editUrl' => route('flights', array_filter([
                'q' => $request->query('q'),
                'edit' => $request->query('q') ? 1 : null,
            ])),
            'summary' => [
                'airline' => (string) $request->query('airline', ''),
                'from' => (string) $request->query('from', ''),
                'to' => (string) $request->query('to', ''),
            ],
        ]);
    }

    /**
     * Persist a `quoted` booking from the selected fare. Re-prices server-side; no
     * ticket is issued here.
     */
    public function store(StoreBookingRequest $request, BookingService $bookings): RedirectResponse|JsonResponse
    {
        try {
            $booking = $bookings->createFromQuote(
                $request->user(),
                $request->selection(),
                $request->passengers(),
                $request->contact(),
            );
        } catch (BookingException $e) {
            return $this->storeError($request, $e->getMessage(), 422);
        } catch (TboAirException $e) {
            report($e);

            return $e->isTimeout()
                ? $this->storeError($request, 'The flight provider timed out. Please try again in a moment.', 504)
                : $this->storeError($request, 'We could not confirm this fare — it may have expired. Please search again.', 502);
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
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        return back()->withInput()->withErrors(['booking' => $message]);
    }
}
