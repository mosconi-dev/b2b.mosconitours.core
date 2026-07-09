<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Services\Booking\BookingService;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\TboAir\Exceptions\TboAirException;
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
