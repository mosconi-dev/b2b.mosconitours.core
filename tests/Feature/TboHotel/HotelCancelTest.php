<?php

namespace Tests\Feature\TboHotel;

use App\Enums\BookingProduct;
use App\Enums\BookingStatus;
use App\Enums\Supplier;
use App\Jobs\ReconcileHotelCancellation;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\TboHotel\CancelPolicySet;
use App\Services\TboHotel\HotelBookingService;
use App\Services\TboHotel\TboHotelService;
use App\Services\Wallet\WalletService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Giving the room back, and getting the money right.
 *
 * The charge is the whole difficulty. TBO's Cancel response does not state one and its
 * invoice is the only settlement, so every figure here is our reading of the terms the
 * rate was booked on — which is exactly why it is computed before the call, written
 * down after it, and labelled an estimate wherever an agent can see it.
 */
class HotelCancelTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    private const BASE = 'https://api.tbotechnology.in/HotelAPI';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Queue::fake();

        config([
            'tbohotel.default' => 'test',
            'tbohotel.environments.test.credentials.username' => 'hotel-user',
            'tbohotel.environments.test.credentials.password' => 'hotel-pass',
            'tbohotel.environments.test.base_url' => self::BASE,
            'tbohotel.retry_delay' => 0,
        ]);

        // The ladder is read against "now", so the tests need a fixed one.
        Carbon::setTestNow('2026-09-06 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A confirmed stay with a free window that shut on 5 September and 100% after.
     *
     * @param  array<string, mixed>  $stay
     */
    private function confirmed(array $stay = [], string $total = '4036.02'): Booking
    {
        $agency = Agency::factory()->create();
        $wallet = Wallet::create(['agency_id' => $agency->id, 'currency' => 'PHP', 'balance' => '100000.00']);
        $user = User::factory()->create(['agency_id' => $agency->id]);

        $booking = Booking::create([
            'reference' => 'MT-CANCEL',
            'product' => BookingProduct::Hotel,
            'supplier' => Supplier::TboHotel,
            'user_id' => $user->id,
            'agency_id' => $agency->id,
            'environment' => 'test',
            'status' => BookingStatus::Confirmed,
            'currency' => 'PHP',
            'total_amount' => $total,
            'supplier_reference' => 'WM9CWM',
            'quote' => [], 'quote_raw' => [], 'pax' => [], 'contact' => [],
        ]);

        $booking->hotel()->create(array_replace([
            'hotel_code' => '1012705', 'hotel_name' => 'Jen s Comfy Home',
            'check_in' => '2026-09-11', 'check_out' => '2026-09-13',
            'nights' => 2, 'rooms_count' => 1, 'guest_nationality' => 'PH',
            'booking_code' => 'code', 'confirmation_number' => 'WM9CWM',
            'cancel_policies' => ['all' => [
                ['from' => '2026-08-01 00:00:00', 'chargeType' => 'Fixed', 'charge' => 0.0],
                ['from' => '2026-09-05 00:00:00', 'chargeType' => 'Percentage', 'charge' => 100.0],
            ]],
        ], $stay));

        // The debit the booking was created with, so a refund has something to reverse.
        app(WalletService::class)->debit($wallet, $total, $user, $booking, 'Booking MT-CANCEL');

        return $booking->fresh();
    }

    private function service(): HotelBookingService
    {
        return app(HotelBookingService::class);
    }

    private function balance(Booking $booking): string
    {
        return (string) Wallet::where('agency_id', $booking->agency_id)->value('balance');
    }

    private function fakeCancel(mixed $response): void
    {
        Http::fake([self::BASE.'/Cancel' => $response]);
    }

    private function cancelled(): array
    {
        return ['Status' => ['Code' => 200, 'Description' => 'Cancelled'], 'ConfirmationNumber' => 'WM9CWM'];
    }

    // ------------------------------------------------------------ the arithmetic ----

    /**
     * The applicable rung is the last one whose date has already passed.
     */
    #[DataProvider('ladders')]
    public function test_the_charge_is_read_off_the_ladder(string $now, float $expected): void
    {
        Carbon::setTestNow($now);

        $this->assertSame($expected, $this->service()->cancellationCharge($this->confirmed()));
    }

    /**
     * @return array<string, array{string, float}>
     */
    public static function ladders(): array
    {
        return [
            'before any rung' => ['2026-07-30 09:00:00', 0.0],
            'inside the free window' => ['2026-09-04 23:59:59', 0.0],
            'the moment it turns' => ['2026-09-05 00:00:00', 4036.02],
            'after' => ['2026-09-10 12:00:00', 4036.02],
        ];
    }

    public function test_a_fixed_charge_is_taken_as_an_amount(): void
    {
        $booking = $this->confirmed(['cancel_policies' => ['all' => [
            ['from' => '2026-09-05 00:00:00', 'chargeType' => 'Fixed', 'charge' => 750.0],
        ]]]);

        $this->assertSame(750.0, $this->service()->cancellationCharge($booking));
    }

    /**
     * A rate whose terms we never received is treated as free rather than guessed at.
     * Guessing high charges an agency for our own missing data.
     */
    public function test_an_absent_policy_costs_nothing(): void
    {
        $this->assertSame(0.0, $this->service()->cancellationCharge($this->confirmed(['cancel_policies' => null])));
    }

    /**
     * A cancellation cannot cost more than the stay did.
     */
    public function test_the_charge_is_capped_at_what_was_paid(): void
    {
        $booking = $this->confirmed(['cancel_policies' => ['all' => [
            ['from' => '2026-09-05 00:00:00', 'chargeType' => 'Fixed', 'charge' => 99999.0],
        ]]]);

        $this->assertSame(4036.02, $this->service()->cancellationCharge($booking));
    }

    /**
     * Per-room policies are TBO's alternative to the whole-booking bucket, not an
     * addition to it — summing both would charge the same stay twice.
     */
    public function test_per_room_policies_are_summed_over_their_own_share(): void
    {
        $set = CancelPolicySet::fromStored([
            '1' => [['from' => '2026-09-05 00:00:00', 'chargeType' => 'Percentage', 'charge' => 100.0]],
            '2' => [['from' => '2026-09-05 00:00:00', 'chargeType' => 'Percentage', 'charge' => 50.0]],
        ]);

        // Two rooms sharing a 4,000 total: one loses its whole 2,000, the other half.
        $this->assertSame(3000.0, $set->chargeAt(Carbon::parse('2026-09-06'), 4000.0, rooms: 2));
    }

    // -------------------------------------------------------------- the cancel ----

    public function test_a_free_cancellation_returns_everything(): void
    {
        Carbon::setTestNow('2026-09-01 09:00:00');
        $booking = $this->confirmed();
        $this->fakeCancel(Http::response($this->cancelled()));

        $cancelled = $this->service()->cancel($booking);

        $this->assertSame(BookingStatus::Cancelled, $cancelled->status);
        $this->assertSame('0.00', (string) $cancelled->hotel->cancellation_charge);
        $this->assertNotNull($cancelled->hotel->cancelled_at);
        $this->assertSame('100000.00', $this->balance($booking));
    }

    /**
     * Per-room statuses read before the cancellation describe a booking that no longer
     * exists — left in place they print "reported Cancelled · Room 1 not cancelled".
     */
    public function test_cancelling_does_not_leave_stale_room_statuses_behind(): void
    {
        $booking = $this->confirmed(['room_statuses' => [['name' => 'Studio', 'status' => 'Not Cancelled']]]);
        $this->fakeCancel(Http::response($this->cancelled()));

        $stay = $this->service()->cancel($booking)->hotel;

        $this->assertSame('Cancelled', $stay->supplier_status);
        $this->assertNull($stay->room_statuses);
        $this->assertNotNull($stay->refreshed_at);
    }

    /**
     * The refund and the fee are two lines, not one net figure: that is what happened,
     * and a single number nobody can reconstruct is what a disputed invoice argues with.
     */
    public function test_a_chargeable_cancellation_refunds_in_full_then_takes_the_fee(): void
    {
        $booking = $this->confirmed(['cancel_policies' => ['all' => [
            ['from' => '2026-09-05 00:00:00', 'chargeType' => 'Fixed', 'charge' => 1000.0],
        ]]]);
        $this->fakeCancel(Http::response($this->cancelled()));

        $cancelled = $this->service()->cancel($booking);

        $this->assertSame('1000.00', (string) $cancelled->hotel->cancellation_charge);
        // 100,000 − 4,036.02 booked, + 4,036.02 refunded, − 1,000 charge.
        $this->assertSame('99000.00', $this->balance($booking));

        $lines = $booking->walletTransactions()->orderBy('id')->get();
        $this->assertCount(3, $lines, 'the debit, the refund, and the fee');
        $this->assertStringContainsString('estimated', (string) $lines->last()->description);
    }

    /**
     * A booker with no agency was never debited, so a fee would be the first money this
     * booking ever moved.
     */
    public function test_a_booking_that_was_never_charged_is_never_charged_a_fee(): void
    {
        $booking = $this->confirmed();
        $booking->walletTransactions()->delete();
        $booking->forceFill(['agency_id' => null])->saveQuietly();

        $this->fakeCancel(Http::response($this->cancelled()));

        $cancelled = $this->service()->cancel($booking->fresh());

        $this->assertSame(BookingStatus::Cancelled, $cancelled->status);
        $this->assertCount(0, $cancelled->walletTransactions);
    }

    /**
     * 479 is a refusal, and the guest still has a room. Refunding here would give an
     * agency money back for a stay that is going ahead.
     */
    public function test_a_refused_cancellation_leaves_the_booking_standing(): void
    {
        $booking = $this->confirmed();
        $this->fakeCancel(Http::response([
            'Status' => ['Code' => 479, 'Description' => 'Cancellation not allowed for this booking'],
        ]));

        try {
            $this->service()->cancel($booking);
            $this->fail('a refusal should not pass silently');
        } catch (BookingException $e) {
            $this->assertStringContainsString('Cancellation not allowed', $e->getMessage());
        }

        $booking->refresh();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertNull($booking->hotel->cancelled_at);
        $this->assertSame('95963.98', $this->balance($booking), 'no refund on a refusal');
    }

    /**
     * Silence is the dangerous one: the room may already be released. The booking waits
     * in an honest state and the answer is read back.
     */
    public function test_an_unanswered_cancellation_waits_rather_than_guesses(): void
    {
        $booking = $this->confirmed();
        $this->fakeCancel(fn () => throw new ConnectionException('timed out'));

        $result = $this->service()->cancel($booking);

        $this->assertSame(BookingStatus::Cancelling, $result->status);
        $this->assertTrue($result->status->isInFlight());
        $this->assertSame('95963.98', $this->balance($booking), 'nothing refunded on a maybe');

        Queue::assertPushed(ReconcileHotelCancellation::class,
            fn (ReconcileHotelCancellation $job): bool => $job->bookingId === $booking->getKey());
    }

    /**
     * A second Cancel against a booking TBO already released answers 479 — the same
     * thing a genuine refusal says. It must never be sent.
     */
    public function test_a_cancellation_in_flight_is_not_sent_again(): void
    {
        $booking = $this->confirmed();
        $booking->forceFill(['status' => BookingStatus::Cancelling])->saveQuietly();
        $this->fakeCancel(Http::response($this->cancelled()));

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('is cancelling and cannot be cancelled');

        try {
            $this->service()->cancel($booking->fresh());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_cancelled_booking_is_not_cancelled_twice(): void
    {
        $booking = $this->confirmed();
        $this->fakeCancel(Http::response($this->cancelled()));
        $this->service()->cancel($booking);

        $this->expectException(BookingException::class);

        $this->service()->cancel($booking->fresh());
    }

    public function test_it_refuses_to_cancel_across_environments(): void
    {
        $booking = $this->confirmed();
        $booking->forceFill(['environment' => 'live'])->saveQuietly();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('cannot be read on test');

        try {
            $this->service()->cancel($booking->fresh());
        } finally {
            Http::assertNothingSent();
        }
    }

    // ------------------------------------------------------------------ the page ----

    private function agentFor(Booking $booking, array $permissions): User
    {
        $user = $this->userWith($permissions);
        $user->forceFill(['agency_id' => $booking->agency_id])->save();
        $booking->forceFill(['user_id' => $user->id])->saveQuietly();

        return $user->fresh();
    }

    /**
     * The agent is shown what it costs before they can commit to it.
     */
    public function test_the_page_shows_the_charge_and_the_refund_before_confirming(): void
    {
        $booking = $this->confirmed(['cancel_policies' => ['all' => [
            ['from' => '2026-09-05 00:00:00', 'chargeType' => 'Fixed', 'charge' => 1000.0],
        ]]]);

        $this->actingAs($this->agentFor($booking, ['hotel.cancel', 'booking.view']))
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Cancel booking')
            ->assertSee('1,000.00')       // the charge
            ->assertSee('3,036.02')       // what comes back
            ->assertSee('estimated');
    }

    public function test_the_page_says_when_cancelling_is_still_free(): void
    {
        Carbon::setTestNow('2026-09-01 09:00:00');
        $booking = $this->confirmed();

        $this->actingAs($this->agentFor($booking, ['hotel.cancel', 'booking.view']))
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('can still be cancelled free of charge');
    }

    public function test_cancelling_is_its_own_permission(): void
    {
        $booking = $this->confirmed();
        $user = $this->agentFor($booking, ['hotel.view', 'hotel.book', 'booking.view']);

        $this->actingAs($user)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertDontSee('Cancel booking');

        $this->actingAs($user)
            ->post(route('hotels.bookings.cancel', $booking))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_the_route_cancels_and_reports_the_charge(): void
    {
        $booking = $this->confirmed(['cancel_policies' => ['all' => [
            ['from' => '2026-09-05 00:00:00', 'chargeType' => 'Fixed', 'charge' => 1000.0],
        ]]]);
        $this->fakeCancel(Http::response($this->cancelled()));

        $this->actingAs($this->agentFor($booking, ['hotel.cancel', 'booking.view']))
            ->post(route('hotels.bookings.cancel', $booking))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, 'PHP 1,000.00'));

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_the_route_refuses_a_flight_booking(): void
    {
        $booking = $this->confirmed();
        $user = $this->agentFor($booking, ['hotel.cancel', 'booking.view']);
        $booking->forceFill(['product' => BookingProduct::Flight])->saveQuietly();

        $this->actingAs($user)
            ->post(route('hotels.bookings.cancel', $booking->fresh()))
            ->assertNotFound();
    }

    /**
     * The cancelled page has to account for the money, not just the room.
     */
    public function test_a_cancelled_stay_says_what_happened_to_the_money(): void
    {
        $booking = $this->confirmed(['cancel_policies' => ['all' => [
            ['from' => '2026-09-05 00:00:00', 'chargeType' => 'Fixed', 'charge' => 1000.0],
        ]]]);
        $this->fakeCancel(Http::response($this->cancelled()));
        $this->service()->cancel($booking);

        $this->actingAs($this->agentFor($booking, ['hotel.cancel', 'booking.view']))
            ->get(route('bookings.show', $booking->fresh()))
            ->assertOk()
            ->assertSee('This stay was cancelled')
            ->assertSee('1,000.00')
            ->assertDontSee('The airline did not issue a ticket');
    }

    /**
     * The cancellation reconcile reads the booking back and never cancels again.
     */
    public function test_the_reconcile_settles_a_cancellation_by_reading_it(): void
    {
        $booking = $this->confirmed();
        $booking->forceFill(['status' => BookingStatus::Cancelling])->saveQuietly();

        Http::fake([
            self::BASE.'/BookingDetail' => Http::response([
                'Status' => ['Code' => 200],
                'BookingDetail' => ['BookingStatus' => 'Cancelled', 'ConfirmationNumber' => 'WM9CWM'],
            ]),
            self::BASE.'/Cancel' => Http::response($this->cancelled()),
        ]);

        (new ReconcileHotelCancellation($booking->getKey()))->handle(
            app(TboHotelService::class),
            $this->service(),
        );

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
        Http::assertSentCount(1);
    }
}
