<?php

namespace Tests\Feature\TboHotel;

use App\Enums\BookingProduct;
use App\Enums\BookingStatus;
use App\Enums\Supplier;
use App\Jobs\ReconcileHotelBooking;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\User;
use App\Models\Wallet;
use App\Services\TboHotel\HotelBookingService;
use App\Services\TboHotel\TboHotelService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The job that stops a timeout costing an agency a room.
 *
 * §10 is explicit that a Book which failed on the wire may still have created the
 * booking, so the only safe move is to read the reference back. Everything here is
 * about refusing to guess: a booking is confirmed or failed only when TBO says so, and
 * stays in flight — costing the agency its money in the meantime — until it does.
 */
class HotelReconcileTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api.tbotechnology.in/HotelAPI';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tbohotel.default' => 'test',
            'tbohotel.environments.test.credentials.username' => 'hotel-user',
            'tbohotel.environments.test.credentials.password' => 'hotel-pass',
            'tbohotel.environments.test.base_url' => self::BASE,
            'tbohotel.retry_delay' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tbohotel/{$name}.json")), true);
    }

    /**
     * A booking mid-flight: sent to TBO, no answer, money already taken.
     */
    private function processing(string $environment = 'test'): Booking
    {
        $agency = Agency::factory()->create();
        $wallet = Wallet::create(['agency_id' => $agency->id, 'currency' => 'PHP', 'balance' => '100000.00']);
        $user = User::factory()->create(['agency_id' => $agency->id]);

        $booking = Booking::create([
            'reference' => 'MT-RECONCILE',
            'product' => BookingProduct::Hotel,
            'supplier' => Supplier::TboHotel,
            'user_id' => $user->id,
            'agency_id' => $agency->id,
            'environment' => $environment,
            'status' => BookingStatus::Processing,
            'currency' => 'PHP',
            'total_amount' => '4036.02',
            'quote' => [], 'quote_raw' => [],
            'pax' => [], 'contact' => [],
        ]);

        $booking->hotel()->create([
            'hotel_code' => '1012705', 'hotel_name' => 'Jen s Comfy Home',
            'check_in' => '2026-09-11', 'check_out' => '2026-09-13',
            'nights' => 2, 'rooms_count' => 1, 'guest_nationality' => 'PH',
            'booking_code' => 'code',
        ]);

        // The debit the booking was created with, so a refund has something to reverse.
        app(WalletService::class)->debit($wallet, '4036.02', $user, $booking, 'Booking MT-RECONCILE');

        return $booking->fresh();
    }

    private function reconcile(Booking $booking, int $attempt = 1): void
    {
        (new ReconcileHotelBooking($booking->getKey(), $attempt))->handle(
            app(TboHotelService::class),
            app(HotelBookingService::class),
        );
    }

    // ------------------------------------------------------------- resolution ----

    /**
     * The booking existed all along. Confirm it and keep the money.
     */
    public function test_a_confirmed_booking_is_recovered(): void
    {
        $booking = $this->processing();
        Http::fake([self::BASE.'/BookingDetail' => Http::response($this->fixture('bookingdetail'))]);

        $this->reconcile($booking);

        $booking->refresh();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame('WM9CWM', $booking->supplier_reference);
        $this->assertSame('WM9CWM', $booking->hotel->confirmation_number);
        $this->assertFalse($booking->wasRefundedToWallet());
    }

    /**
     * It is read back by *our* reference, because a timed-out Book never gave us TBO's.
     */
    public function test_it_asks_by_our_own_reference(): void
    {
        $booking = $this->processing();
        Http::fake([self::BASE.'/BookingDetail' => Http::response($this->fixture('bookingdetail'))]);

        $this->reconcile($booking);

        Http::assertSent(function (Request $request) use ($booking): bool {
            $this->assertSame($booking->reference, $request->data()['BookingReferenceId']);
            $this->assertArrayNotHasKey('ConfirmationNumber', $request->data());

            return true;
        });
    }

    /**
     * TBO says no room was ever held, so the agency gets its money back.
     */
    public function test_a_failed_booking_is_recorded_and_refunded(): void
    {
        $booking = $this->processing();
        Http::fake([self::BASE.'/BookingDetail' => Http::response([
            'Status' => ['Code' => 200, 'Description' => 'Successful'],
            'BookingDetail' => ['BookingStatus' => 'Failed', 'ConfirmationNumber' => null],
        ])]);

        $this->reconcile($booking);

        $this->assertSame(BookingStatus::Failed, $booking->fresh()->status);
        $this->assertTrue($booking->fresh()->wasRefundedToWallet());
    }

    // ------------------------------------------------------ refusing to guess ----

    /**
     * "Confirmed" with nothing to name it by is not an answer: a booking we cannot
     * reference is one we could never cancel.
     */
    public function test_confirmed_without_a_reference_is_not_accepted(): void
    {
        Queue::fake();

        $booking = $this->processing();
        Http::fake([self::BASE.'/BookingDetail' => Http::response([
            'Status' => ['Code' => 200, 'Description' => 'Successful'],
            'BookingDetail' => ['BookingStatus' => 'Confirmed', 'ConfirmationNumber' => ''],
        ])]);

        $this->reconcile($booking);

        $this->assertSame(BookingStatus::Processing, $booking->fresh()->status);
        Queue::assertPushed(ReconcileHotelBooking::class);
    }

    /**
     * A state we do not recognise is a state we must not act on. Acting on a
     * half-answer is how a real reservation gets refunded.
     */
    public function test_an_unrecognised_status_is_asked_again_rather_than_guessed(): void
    {
        Queue::fake();

        $booking = $this->processing();
        Http::fake([self::BASE.'/BookingDetail' => Http::response([
            'Status' => ['Code' => 200, 'Description' => 'Successful'],
            'BookingDetail' => ['BookingStatus' => 'InProgress', 'ConfirmationNumber' => 'X1'],
        ])]);

        $this->reconcile($booking);

        $this->assertSame(BookingStatus::Processing, $booking->fresh()->status);
        $this->assertFalse($booking->fresh()->wasRefundedToWallet());
        Queue::assertPushed(ReconcileHotelBooking::class,
            fn (ReconcileHotelBooking $job): bool => $job->attempt === 2);
    }

    /**
     * Not being able to ask is not an answer either.
     */
    public function test_a_failed_read_is_retried_rather_than_resolved(): void
    {
        Queue::fake();

        $booking = $this->processing();
        Http::fake([self::BASE.'/BookingDetail' => Http::response('', 504)]);

        $this->reconcile($booking);

        $this->assertSame(BookingStatus::Processing, $booking->fresh()->status);
        Queue::assertPushed(ReconcileHotelBooking::class);
    }

    /**
     * Eventually it stops being a retry and becomes a support question — but the
     * booking still is not guessed at, and the money still is not moved.
     */
    public function test_it_gives_up_asking_without_ever_deciding(): void
    {
        Queue::fake();
        config(['tbohotel.reconcile_attempts' => 3]);

        $booking = $this->processing();
        Http::fake([self::BASE.'/BookingDetail' => Http::response([
            'Status' => ['Code' => 200, 'Description' => 'Successful'],
            'BookingDetail' => ['BookingStatus' => 'InProgress'],
        ])]);

        $this->reconcile($booking, attempt: 3);

        $this->assertSame(BookingStatus::Processing, $booking->fresh()->status);
        $this->assertFalse($booking->fresh()->wasRefundedToWallet());
        Queue::assertNotPushed(ReconcileHotelBooking::class);
    }

    // ------------------------------------------------------------- safeguards ----

    /**
     * A second delivery of the job must not undo an ending already reached.
     */
    public function test_a_settled_booking_is_left_alone(): void
    {
        $booking = $this->processing();
        $booking->forceFill(['status' => BookingStatus::Confirmed])->saveQuietly();

        Http::fake();

        $this->reconcile($booking->fresh());

        Http::assertNothingSent();
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    /**
     * Reading test data against a live account answers the wrong question entirely.
     */
    public function test_it_refuses_to_read_across_environments(): void
    {
        $booking = $this->processing(environment: 'live');

        Http::fake();

        $this->reconcile($booking);

        Http::assertNothingSent();
        $this->assertSame(BookingStatus::Processing, $booking->fresh()->status);
    }

    /**
     * The reference is spent. Asking TBO to book it again could buy the room twice,
     * which is worse than a late answer.
     */
    public function test_it_never_books_again(): void
    {
        $booking = $this->processing();
        Http::fake([self::BASE.'/BookingDetail' => Http::response($this->fixture('bookingdetail'))]);

        $this->reconcile($booking);

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/Book'));
    }

    /**
     * TBO omits these entirely until it issues them — HotelConfirmationNumber only
     * within thirty days of check-in — so absence must not become an empty string.
     */
    public function test_a_reference_tbo_has_not_issued_yet_is_left_null(): void
    {
        $booking = $this->processing();
        Http::fake([self::BASE.'/BookingDetail' => Http::response($this->fixture('bookingdetail'))]);

        $this->reconcile($booking);

        $detail = $booking->fresh()->hotel;
        $this->assertNull($detail->hotel_confirmation_number);
        $this->assertNull($detail->invoice_number);
        $this->assertTrue($detail->awaitingHotelConfirmation());
    }
}
