<?php

namespace Tests\Feature\TboHotel;

use App\Enums\BookingStatus;
use App\Jobs\BookHotelJob;
use App\Jobs\ReconcileHotelBooking;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\TboHotel\DTO\Guest;
use App\Services\TboHotel\DTO\PaxRoom;
use App\Services\TboHotel\DTO\SearchInput;
use App\Services\TboHotel\HotelBookingService;
use App\Services\TboHotel\TboHotelBookPayload;
use App\Services\TboHotel\TboHotelClient;
use App\Services\TboHotel\TboHotelConfig;
use App\Services\TboHotel\TboHotelService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Book is the money step. What is pinned here is mostly the difference between "TBO
 * said no" and "TBO did not answer" — getting that wrong either refunds an agency for
 * a room its guest will turn up to, or charges them for one that was never held.
 */
class HotelBookTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api.tbotechnology.in/HotelAPI';

    private const CODE = '1012705!TB!1!TB!f8cea260-96bf-11f1-a512-aa71e0cecaa6!TB!N!TB!AFF!';

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

        // The test queue is sync, so a dispatched reconcile would run inside the call
        // it is meant to follow. Its own behaviour is pinned in HotelReconcileTest.
        Queue::fake();

        Hotel::create([
            'source' => 'tbo', 'code' => '1012705', 'city_code' => '127116',
            'country_code' => 'PH', 'name' => 'Jen s Comfy Home', 'rating' => 3,
        ]);
    }

    private function service(): HotelBookingService
    {
        return new HotelBookingService(
            new TboHotelService(new TboHotelClient(TboHotelConfig::for('test'))),
            app(WalletService::class),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tbohotel/{$name}.json")), true);
    }

    /**
     * A booking already through PreBook and paid for, ready to send.
     */
    private function quoted(int $rooms = 1, string $balance = '100000.00'): Booking
    {
        $agency = Agency::factory()->create();
        Wallet::create(['agency_id' => $agency->id, 'currency' => 'PHP', 'balance' => $balance]);
        $user = User::factory()->create(['agency_id' => $agency->id]);

        $guests = [
            new Guest('Mr', 'Juan', 'Dela Cruz', Guest::ADULT, 0, true),
            new Guest('Mrs', 'Ana', 'Dela Cruz', Guest::ADULT, 0),
        ];
        $paxRooms = [new PaxRoom(2, 0, [])];

        if ($rooms === 2) {
            $guests[] = new Guest('Mr', 'Jose', 'Rizal', Guest::ADULT, 1);
            $guests[] = new Guest('Ms', 'Maria', 'Rizal', Guest::ADULT, 1);
            $paxRooms[] = new PaxRoom(2, 0, []);
        }

        $booking = $this->service()->createFromQuote(
            $user,
            new SearchInput('2026-09-11', '2026-09-13', $paxRooms, 'PH', 'hotel', '1012705'),
            self::CODE,
            $guests,
            ['email' => 'agent@example.test', 'phone' => '+639171234567'],
        );

        return $booking->fresh();
    }

    /**
     * Registered in one call, before anything runs. Http::fake() stubs accumulate and
     * the first match wins, so a second call cannot narrow an earlier one.
     */
    private function fakeBook(mixed $response): void
    {
        Http::fake([
            self::BASE.'/PreBook' => Http::response($this->fixture('prebook')),
            self::BASE.'/Book' => $response,
        ]);
    }

    /** Only the Book calls — PreBook shares the recorder. */
    private function bookRequests(): int
    {
        $count = 0;

        Http::recorded(function (Request $request) use (&$count): bool {
            if (str_ends_with($request->url(), '/Book')) {
                $count++;
            }

            return true;
        });

        return $count;
    }

    // ---------------------------------------------------------------- payload ----

    public function test_the_payload_is_assembled_from_what_was_stored(): void
    {
        $this->fakeBook(Http::response($this->fixture('book')));
        $booking = $this->quoted();
        $payload = TboHotelBookPayload::for($booking);

        $this->assertSame(self::CODE, $payload['BookingCode']);
        $this->assertSame((float) $booking->net_amount, $payload['TotalFare']);
        $this->assertSame('Voucher', $payload['BookingType']);
        $this->assertSame('Limit', $payload['PaymentMode']);
        $this->assertSame('agent@example.test', $payload['EmailId']);

        // Both references are ours and identical. BookingReferenceId is the key a
        // timed-out Book is read back by, so it cannot be anything TBO issues.
        $this->assertSame($booking->reference, $payload['ClientReferenceId']);
        $this->assertSame($booking->reference, $payload['BookingReferenceId']);
    }

    /**
     * The supplier is sent the supplier's own number, never ours.
     *
     * TBO compares TotalFare against its own figure and refuses a mismatch, so a sell
     * price in that field breaks every hotel booking. Today net and total are equal and
     * the assertion above cannot tell them apart — this one forces them apart first.
     */
    public function test_the_payload_sends_net_not_the_marked_up_sell_price(): void
    {
        $this->fakeBook(Http::response($this->fixture('book')));
        $booking = $this->quoted();

        $booking->update(['total_amount' => '9999.00', 'markup_total' => '9999.00']);

        $payload = TboHotelBookPayload::for($booking->fresh());

        $this->assertSame((float) $booking->net_amount, $payload['TotalFare']);
        $this->assertNotSame(9999.0, $payload['TotalFare'], 'our markup must never reach TBO');
    }

    /**
     * TBO allocates beds from these groups, so a guest in the wrong one sleeps in the
     * wrong room.
     */
    public function test_guests_are_grouped_into_the_rooms_they_sleep_in(): void
    {
        $this->fakeBook(Http::response($this->fixture('book')));
        $payload = TboHotelBookPayload::for($this->quoted(rooms: 2));

        $this->assertCount(2, $payload['CustomerDetails']);
        $this->assertCount(2, $payload['CustomerDetails'][0]['CustomerNames']);
        $this->assertSame('Juan', $payload['CustomerDetails'][0]['CustomerNames'][0]['FirstName']);
        $this->assertSame('Jose', $payload['CustomerDetails'][1]['CustomerNames'][0]['FirstName']);
        $this->assertSame('Adult', $payload['CustomerDetails'][1]['CustomerNames'][0]['Type']);
    }

    // ------------------------------------------------------------------- book ----

    public function test_a_successful_book_confirms_and_records_the_number(): void
    {
        $this->fakeBook(Http::response($this->fixture('book')));
        $booking = $this->quoted();

        $booked = $this->service()->book($booking);

        $this->assertSame(BookingStatus::Confirmed, $booked->status);
        $this->assertSame('WM9CWM', $booked->supplier_reference);
        $this->assertSame('WM9CWM', $booked->hotel->confirmation_number);
    }

    public function test_the_money_stays_taken_on_a_confirmed_booking(): void
    {
        $this->fakeBook(Http::response($this->fixture('book')));
        $booking = $this->quoted();

        $this->service()->book($booking);

        $this->assertNotNull($booking->fresh()->walletCharge());
        $this->assertFalse($booking->fresh()->wasRefundedToWallet());
    }

    /**
     * TBO answered, so this is a refusal: no room was held and the agency should not be
     * paying for one.
     */
    public function test_a_refusal_fails_the_booking_and_refunds(): void
    {
        $this->fakeBook(Http::response(['Status' => ['Code' => 300, 'Description' => 'Insufficient credit limit']]));
        $booking = $this->quoted();

        try {
            $this->service()->book($booking);
            $this->fail('A refusal should have thrown.');
        } catch (BookingException $e) {
            $this->assertStringContainsString('Insufficient credit limit', $e->getMessage());
        }

        $this->assertSame(BookingStatus::Failed, $booking->fresh()->status);
        $this->assertTrue($booking->fresh()->wasRefundedToWallet());
    }

    /**
     * The case this whole phase exists for. §10 assumes a timed-out Book may well have
     * succeeded, so nothing is decided and nothing is refunded — the booking stays in
     * flight and the reference gets read back.
     */
    public function test_a_timeout_leaves_the_booking_in_flight_and_queues_a_reconcile(): void
    {
        Queue::fake();

        $this->fakeBook(fn () => throw new ConnectionException('timed out'));
        $booking = $this->quoted();

        $this->service()->book($booking);

        $this->assertSame(BookingStatus::Processing, $booking->fresh()->status);
        $this->assertFalse($booking->fresh()->wasRefundedToWallet(), 'the room may exist; the money stays put');

        Queue::assertPushed(ReconcileHotelBooking::class,
            fn (ReconcileHotelBooking $job): bool => $job->bookingId === $booking->getKey());
    }

    /**
     * A booking we cannot name is one we cannot cancel or void, so "yes" without a
     * reference is not an answer either.
     */
    public function test_a_success_without_a_confirmation_number_is_reconciled_not_confirmed(): void
    {
        Queue::fake();

        $this->fakeBook(Http::response(['Status' => ['Code' => 200, 'Description' => 'Successful']]));
        $booking = $this->quoted();

        $this->service()->book($booking);

        $this->assertSame(BookingStatus::Processing, $booking->fresh()->status);
        Queue::assertPushed(ReconcileHotelBooking::class);
    }

    /**
     * Book is not idempotent, so a second press must not reach TBO twice.
     */
    public function test_a_booking_already_confirmed_is_not_booked_again(): void
    {
        $this->fakeBook(Http::response($this->fixture('book')));
        $booking = $this->quoted();
        $this->service()->book($booking);

        Http::fake([self::BASE.'/Book' => Http::response($this->fixture('book'))]);

        $this->expectException(BookingException::class);

        $before = $this->bookRequests();

        try {
            $this->service()->book($booking->fresh());
        } finally {
            $this->assertSame($before, $this->bookRequests(), 'no second Book reached TBO');
        }
    }

    /**
     * The ambiguous case, which is the one that matters.
     *
     * A Book that went out and was never answered leaves the booking `processing` with
     * a send time and no confirmation number — indistinguishable by status from one
     * merely queued. It may already hold the room, so it is settled by reading the
     * reference back, never by asking again.
     */
    public function test_a_booking_already_on_the_wire_is_never_sent_again(): void
    {
        $this->fakeBook(Http::response($this->fixture('book')));

        $booking = $this->quoted();
        $booking->hotel->update(['book_sent_at' => now()]);
        $booking->forceFill(['status' => BookingStatus::Processing])->saveQuietly();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('already been sent');

        try {
            $this->service()->book($booking->fresh());
        } finally {
            $this->assertSame(0, $this->bookRequests(), 'nothing was sent');
        }
    }

    /**
     * The job is the caller that can be delivered twice, so it has to stop before the
     * service does — a second delivery must not even reach the lock.
     */
    public function test_a_redelivered_job_does_not_send_a_second_book(): void
    {
        $this->fakeBook(Http::response($this->fixture('book')));

        $booking = $this->quoted();
        $booking->hotel->update(['book_sent_at' => now()]);
        $booking->forceFill(['status' => BookingStatus::Processing])->saveQuietly();

        (new BookHotelJob($booking->getKey()))->handle(app(HotelBookingService::class));

        $this->assertSame(0, $this->bookRequests(), 'nothing was sent');
        $this->assertSame(BookingStatus::Processing, $booking->fresh()->status);
    }

    /**
     * The send time is written before the request, not after: a worker that dies
     * mid-call leaves a reservation we may own and no answer saying so.
     */
    public function test_the_send_is_recorded_before_the_answer_is_known(): void
    {
        $this->fakeBook(Http::response($this->fixture('book')));

        $booking = $this->service()->book($this->quoted());

        $this->assertNotNull($booking->hotel->fresh()->book_sent_at);
    }

    /**
     * A booking quoted on test must never be sent to production, whatever the platform
     * has been switched to since.
     */
    public function test_a_booking_is_refused_on_a_different_environment(): void
    {
        $this->fakeBook(Http::response($this->fixture('book')));
        $booking = $this->quoted();
        $booking->forceFill(['environment' => 'live'])->saveQuietly();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('cannot be booked on test');

        try {
            $this->service()->book($booking->fresh());
        } finally {
            $this->assertSame(0, $this->bookRequests(), 'nothing was sent');
        }
    }

    public function test_the_supplier_call_is_logged(): void
    {
        $this->fakeBook(Http::response($this->fixture('book')));
        $booking = $this->quoted();

        $this->service()->book($booking);

        $this->assertDatabaseHas('supplier_api_logs', [
            'supplier' => 'tbohotel', 'type' => 'book', 'successful' => true,
        ]);
    }

    /**
     * A Book that appears to have been rejected cannot be proven not to have landed, so
     * it is the one call the transport must never repeat.
     *
     * Exercised with a 429, which every read here *does* retry — if Book shared that
     * behaviour the second response would win and this would pass as a booking.
     */
    public function test_a_book_is_never_retried(): void
    {
        Http::fake([
            self::BASE.'/PreBook' => Http::response($this->fixture('prebook')),
            self::BASE.'/Book' => Http::sequence()
                ->push(['Status' => ['Code' => 429, 'Description' => 'Too many requests']], 200)
                ->push($this->fixture('book'), 200),
        ]);

        $booking = $this->quoted();

        try {
            $this->service()->book($booking);
            $this->fail('A throttled Book should not have been retried into a success.');
        } catch (BookingException) {
            // expected
        }

        $this->assertSame(1, $this->bookRequests(), 'exactly one Book reached TBO');
        $this->assertSame(BookingStatus::Failed, $booking->fresh()->status);
    }

    public function test_it_posts_the_payload_verbatim(): void
    {
        $this->fakeBook(Http::response($this->fixture('book')));
        $booking = $this->quoted();

        $this->service()->book($booking);

        // PreBook shares the recorder, so the Book request is picked out rather than
        // asserted against every call made.
        Http::assertSent(function (Request $request) use ($booking): bool {
            if (! str_ends_with($request->url(), '/Book')) {
                return false;
            }

            $this->assertSame($booking->reference, $request->data()['BookingReferenceId']);
            $this->assertSame($booking->reference, $request->data()['ClientReferenceId']);
            $this->assertSame('Voucher', $request->data()['BookingType']);
            $this->assertSame('Limit', $request->data()['PaymentMode']);

            return true;
        });
    }
}
