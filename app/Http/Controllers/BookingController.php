<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLoadRequest;
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
    /**
     * A user's own bookings. The agency scope is applied on top of ownership: it is
     * redundant today (your own booking is always your agency's) but keeps the list
     * correct if visibility is ever widened to "everyone in my agency".
     */
    public function index(Request $request): View
    {
        $bookings = Booking::visibleTo($request->user())
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return view('bookings.index', compact('bookings'));
    }

    public function show(Request $request, Booking $booking): View
    {
        abort_unless(
            $booking->user_id === $request->user()->id && $booking->isVisibleTo($request->user()),
            403,
        );

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
            // Per-segment seat availability from the search, comma-separated in segment
            // order. FareQuote does not return it and Book requires it — see the
            // seats_available migration.
            'seats' => ['nullable', 'string', 'max:255', 'regex:/^[0-9,]*$/'],
            // Search-only too: TBO's ResultRecommendationType, which Book wants back as
            // ResultType. Observed as both 0 and 1, so it cannot be assumed.
            'resultType' => ['nullable', 'integer', 'min:0', 'max:99'],
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
            'seats' => $this->seats($data['seats'] ?? null),
            'resultType' => isset($data['resultType']) ? (int) $data['resultType'] : null,
            'search' => (string) $request->query('search', ''),
            // The encoded search token — pre-fills the in-place "Modify" form.
            'q' => (string) $request->query('q', ''),
            'summary' => [
                'airline' => (string) $request->query('airline', ''),
                'from' => (string) $request->query('from', ''),
                'to' => (string) $request->query('to', ''),
            ],
            // Lets the Payment step warn about a shortfall before the agent submits.
            // Advisory only — BookingService re-checks under lock at submit, because
            // a colleague may spend the balance while this page is open.
            'wallet' => $this->walletSummary($request->user()),
            'walletRequestUrl' => $request->user()->can('create', WalletLoadRequest::class)
                ? route('wallet.requests.create')
                : null,
        ]);
    }

    /**
     * The booker's agency balance, or null when there is nothing to charge.
     *
     * Deliberately not gated on wallet.view: this is money the agent is about to
     * spend, and the insufficient-funds error already names the figure. Gating it
     * would only move the discovery to after the wizard was filled in.
     *
     * @return array{balance: string, currency: string}|null
     */
    private function walletSummary(User $user): ?array
    {
        if ($user->agency_id === null) {
            return null;
        }

        // Read-only: rendering a form must not create a wallet row.
        $wallet = Wallet::where('agency_id', $user->agency_id)->first(['currency', 'balance']);

        return [
            'balance' => (string) ($wallet?->balance ?? '0.00'),
            'currency' => $wallet?->currency ?? 'PHP',
        ];
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
                $request->seats(),
                $request->resultType(),
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

    /**
     * Hold the PNR for a non-LCC booking. Creates a reservation; spends nothing.
     */
    public function book(Request $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        return $this->runSupplierWrite(
            $request,
            $booking,
            fn (): Booking => $bookings->book($booking, $request->userAgent()),
            fn (Booking $b): string => "Booking {$b->reference} is held with the airline — PNR {$b->pnr}.",
        );
    }

    /**
     * Issue the ticket. **Spends the agency wallet and our supplier balance.**
     */
    public function issue(Request $request, Booking $booking, BookingService $bookings): RedirectResponse
    {
        return $this->runSupplierWrite(
            $request,
            $booking,
            fn (): Booking => $bookings->issue($booking, $request->userAgent()),
            fn (Booking $b): string => "Booking {$b->reference} is ticketed — PNR {$b->pnr}.",
        );
    }

    /**
     * Run a Book/Ticket call and turn its outcome into a message.
     *
     * An **unresolved** outcome is deliberately not styled as a plain error: the
     * booking may hold a live PNR, so the agent must be told to stop rather than
     * invited to try again. Everything else is reported as it happened.
     *
     * @param  callable(): Booking  $write
     * @param  callable(Booking): string  $success
     */
    private function runSupplierWrite(Request $request, Booking $booking, callable $write, callable $success): RedirectResponse
    {
        abort_unless(
            $booking->user_id === $request->user()->id && $booking->isVisibleTo($request->user()),
            403,
        );

        try {
            $booking = $write();
        } catch (BookingException $e) {
            if ($e->unresolved) {
                report($e);
            }

            return back()->with('error', $e->getMessage());
        } catch (TboAirException $e) {
            report($e);

            // A timeout is the dangerous one: the request may have landed. Never
            // suggest retrying — GetBookingDetails has to settle it first.
            return back()->with('error', $e->isTimeout()
                ? "The airline did not respond in time. Booking {$booking->reference} may still have been created — please check it before trying again."
                : 'The airline rejected this request. Please try again, or contact support if it persists.');
        }

        return back()->with('status', $success($booking));
    }

    /**
     * Parse the comma-separated seat list carried over from search.
     *
     * A blank entry means that segment's availability is genuinely unknown, so it
     * stays null rather than becoming 0 — zero seats and "not captured" are different
     * facts, and only one of them should ever stop a booking.
     *
     * @return array<int, int|null>
     */
    private function seats(?string $seats): array
    {
        if (! filled($seats)) {
            return [];
        }

        return array_map(
            fn (string $seat): ?int => trim($seat) === '' ? null : (int) $seat,
            explode(',', $seats),
        );
    }

    private function storeError(Request $request, string $message, int $status): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        return back()->withInput()->withErrors(['booking' => $message]);
    }
}
