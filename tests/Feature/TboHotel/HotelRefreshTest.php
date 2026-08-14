<?php

namespace Tests\Feature\TboHotel;

use App\Enums\BookingProduct;
use App\Enums\BookingStatus;
use App\Enums\Supplier;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\TboHotel\HotelBookingService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Asking TBO what a booking is now.
 *
 * Everything a confirmed stay knows about itself was true the moment it was booked. The
 * hotel's own reference arrives days later, the invoice later still, and a cancellation
 * made in TBO's own portal never reaches us at all. This is the one call that can
 * correct us — and the one that could just as easily corrupt a good booking if it wrote
 * down every half-answer it got.
 */
class HotelRefreshTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    private const BASE = 'https://api.tbotechnology.in/HotelAPI';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

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
     * BookingDetail with whatever fields this test cares about.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function reply(array $detail): array
    {
        return [
            'Status' => ['Code' => 200, 'Description' => 'Successful'],
            'BookingDetail' => $detail + ['ConfirmationNumber' => 'WM9CWM'],
        ];
    }

    private function booking(BookingStatus $status = BookingStatus::Confirmed, array $stay = []): Booking
    {
        $agency = Agency::factory()->create();
        Wallet::create(['agency_id' => $agency->id, 'currency' => 'PHP', 'balance' => '100000.00']);
        $user = User::factory()->create(['agency_id' => $agency->id]);

        $booking = Booking::create([
            'reference' => 'MT-REFRESH',
            'product' => BookingProduct::Hotel,
            'supplier' => Supplier::TboHotel,
            'user_id' => $user->id,
            'agency_id' => $agency->id,
            'environment' => 'test',
            'status' => $status,
            'currency' => 'PHP',
            'total_amount' => '4036.02',
            'supplier_reference' => 'WM9CWM',
            'quote' => [], 'quote_raw' => [], 'pax' => [], 'contact' => [],
        ]);

        $booking->hotel()->create(array_replace([
            'hotel_code' => '1012705', 'hotel_name' => 'Jen s Comfy Home',
            'check_in' => '2026-09-11', 'check_out' => '2026-09-13',
            'nights' => 2, 'rooms_count' => 1, 'guest_nationality' => 'PH',
            'booking_code' => 'code', 'confirmation_number' => 'WM9CWM',
        ], $stay));

        return $booking->fresh();
    }

    private function refresh(Booking $booking): Booking
    {
        return app(HotelBookingService::class)->refresh($booking);
    }

    // ------------------------------------------------------------ what it reads ----

    /**
     * The two references that arrive after the booking does.
     */
    public function test_it_records_the_hotel_reference_when_tbo_finally_issues_one(): void
    {
        $booking = $this->booking();
        Http::fake([self::BASE.'/BookingDetail' => Http::response($this->reply([
            'BookingStatus' => 'Confirmed',
            'HotelConfirmationNumber' => 'HTL-778',
            'InvoiceNumber' => 'INV-2211',
        ]))]);

        $stay = $this->refresh($booking)->hotel;

        $this->assertSame('HTL-778', $stay->hotel_confirmation_number);
        $this->assertSame('INV-2211', $stay->invoice_number);
        $this->assertSame('Confirmed', $stay->supplier_status);
        $this->assertNotNull($stay->refreshed_at);
    }

    /**
     * TBO omits the hotel's reference until it issues one, and an omission must never
     * erase a reference we already hold — a guest at a desk is reading ours.
     */
    public function test_an_absent_reference_does_not_erase_the_one_we_have(): void
    {
        $booking = $this->booking(stay: ['hotel_confirmation_number' => 'HTL-778']);
        Http::fake([self::BASE.'/BookingDetail' => Http::response($this->reply([
            'BookingStatus' => 'Confirmed',
        ]))]);

        $this->assertSame('HTL-778', $this->refresh($booking)->hotel->hotel_confirmation_number);
    }

    /**
     * A multi-room booking can be cancelled a room at a time (24 Apr 2026), which our
     * single booking status cannot express.
     */
    public function test_per_room_status_is_kept(): void
    {
        $booking = $this->booking();
        Http::fake([self::BASE.'/BookingDetail' => Http::response($this->reply([
            'BookingStatus' => 'Confirmed',
            'Rooms' => [
                ['Name' => ['Standard Studio, 1 Queen Bed'], 'Status' => 'Not Cancelled'],
                ['Name' => ['Deluxe Room, 2 Twin Beds'], 'Status' => 'Cancelled'],
            ],
        ]))]);

        $rooms = $this->refresh($booking)->hotel->room_statuses;

        $this->assertCount(2, $rooms);
        $this->assertSame('Not Cancelled', $rooms[0]['status']);
        $this->assertSame('Deluxe Room, 2 Twin Beds', $rooms[1]['name']);
        $this->assertSame('Cancelled', $rooms[1]['status']);
    }

    /**
     * §18 makes PreBook's terms final for the itinerary. What BookingDetail says about
     * them later is a second opinion on a contract already signed — and the voucher in
     * the guest's hand was printed from the first one.
     */
    public function test_it_does_not_rewrite_the_terms_the_booking_was_made_on(): void
    {
        $booking = $this->booking(stay: [
            'rate_conditions' => ['<p>Photo ID required at check-in.</p>'],
            'cancel_policies' => ['all' => [['from' => '2026-09-04 00:00:00', 'charge' => 0.0]]],
        ]);

        Http::fake([self::BASE.'/BookingDetail' => Http::response($this->reply([
            'BookingStatus' => 'Confirmed',
            'RateConditions' => ['Everything has changed and nothing is refundable.'],
        ]))]);

        $stay = $this->refresh($booking)->hotel;

        $this->assertSame(['<p>Photo ID required at check-in.</p>'], $stay->rate_conditions);
        $this->assertNotEmpty($stay->cancel_policies);
    }

    // ----------------------------------------------------------- what it changes ----

    /**
     * Someone cancelled it somewhere else — TBO's portal, the property, TBO itself. The
     * agency is not paying for a room nobody is holding.
     */
    public function test_a_booking_cancelled_elsewhere_is_brought_into_line(): void
    {
        $booking = $this->booking();
        Http::fake([self::BASE.'/BookingDetail' => Http::response($this->reply([
            'BookingStatus' => 'Cancelled',
        ]))]);

        $this->assertSame(BookingStatus::Cancelled, $this->refresh($booking)->status);
    }

    /**
     * A cancellation TBO has not yet honoured is not a cancelled booking.
     */
    public function test_a_cancellation_in_progress_is_reported_as_in_flight(): void
    {
        $booking = $this->booking();
        Http::fake([self::BASE.'/BookingDetail' => Http::response($this->reply([
            'BookingStatus' => 'CancellationInProgress',
        ]))]);

        $refreshed = $this->refresh($booking);

        $this->assertSame(BookingStatus::Cancelling, $refreshed->status);
        $this->assertTrue($refreshed->status->isInFlight());
    }

    /**
     * A stale read must not un-cancel a booking. TBO's own list lags its cancellations,
     * and the state machine is the thing that knows which way time runs.
     */
    public function test_a_stale_confirmed_read_does_not_resurrect_a_cancelled_booking(): void
    {
        $booking = $this->booking(BookingStatus::Cancelled);
        Http::fake([self::BASE.'/BookingDetail' => Http::response($this->reply([
            'BookingStatus' => 'Confirmed',
        ]))]);

        $refreshed = $this->refresh($booking);

        $this->assertSame(BookingStatus::Cancelled, $refreshed->status);
        // It still writes down what it was told — that disagreement is what support reads.
        $this->assertSame('Confirmed', $refreshed->hotel->supplier_status);
    }

    /**
     * A state we do not recognise is a question, not an instruction.
     */
    public function test_an_unrecognised_state_changes_nothing_but_is_recorded(): void
    {
        $booking = $this->booking();
        Http::fake([self::BASE.'/BookingDetail' => Http::response($this->reply([
            'BookingStatus' => 'AwaitingSomethingNewTboInvented',
        ]))]);

        $refreshed = $this->refresh($booking);

        $this->assertSame(BookingStatus::Confirmed, $refreshed->status);
        $this->assertSame('AwaitingSomethingNewTboInvented', $refreshed->hotel->supplier_status);
    }

    // ----------------------------------------------------------------- guards ----

    /**
     * Asking a live account about a test reference answers the wrong question, and the
     * answer would then be written down as fact.
     */
    public function test_it_refuses_to_read_across_environments(): void
    {
        $booking = $this->booking();
        $booking->forceFill(['environment' => 'live'])->saveQuietly();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('cannot be read on test');

        try {
            $this->refresh($booking->fresh());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_there_is_nothing_to_ask_about_an_unsent_booking(): void
    {
        $booking = $this->booking(BookingStatus::Quoted);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('has not been sent to the hotel yet');

        $this->refresh($booking);
    }

    // ------------------------------------------------------------------ route ----

    public function test_the_page_offers_the_check_and_reports_the_answer(): void
    {
        $booking = $this->booking();
        $user = $this->userWith(['hotel.view', 'booking.view']);
        $user->forceFill(['agency_id' => $booking->agency_id])->save();
        $booking->forceFill(['user_id' => $user->id])->saveQuietly();

        Http::fake([self::BASE.'/BookingDetail' => Http::response($this->reply([
            'BookingStatus' => 'Confirmed',
        ]))]);

        $this->actingAs($user)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Check with hotel')
            ->assertSee('Not checked with the hotel provider since booking.');

        $this->actingAs($user)
            ->post(route('hotels.bookings.refresh', $booking))
            ->assertRedirect()
            ->assertSessionHas('status', 'The hotel provider reports this booking as Confirmed.');

        $this->actingAs($user)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Hotel provider reported');
    }

    public function test_the_check_is_gated_and_owned(): void
    {
        $booking = $this->booking();

        // Someone else's booking, holding every hotel ability.
        $this->actingAs($this->userWith(['hotel.view', 'booking.view']))
            ->post(route('hotels.bookings.refresh', $booking))
            ->assertForbidden();

        $owner = $this->userWith(['booking.view']);
        $owner->forceFill(['agency_id' => $booking->agency_id])->save();
        $booking->forceFill(['user_id' => $owner->id])->saveQuietly();

        $this->actingAs($owner)
            ->post(route('hotels.bookings.refresh', $booking->fresh()))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * The airline chain and the hotel chain share a booking page but not a route.
     */
    public function test_the_route_refuses_a_flight_booking(): void
    {
        $booking = $this->booking();
        $booking->forceFill(['product' => BookingProduct::Flight])->saveQuietly();

        $user = $this->userWith(['hotel.view', 'booking.view']);
        $user->forceFill(['agency_id' => $booking->agency_id])->save();
        $booking->forceFill(['user_id' => $user->id])->saveQuietly();

        $this->actingAs($user)
            ->post(route('hotels.bookings.refresh', $booking->fresh()))
            ->assertNotFound();
    }

    /**
     * The real thing, byte for byte: the booking we made against TEST and cancelled.
     */
    public function test_it_reads_a_real_cancelled_booking(): void
    {
        $booking = $this->booking();
        Http::fake([self::BASE.'/BookingDetail' => Http::response($this->fixture('bookingdetail-cancelled'))]);

        $refreshed = $this->refresh($booking);

        $this->assertSame(BookingStatus::Cancelled, $refreshed->status);
        $this->assertSame('Cancelled', $refreshed->hotel->supplier_status);
        $this->assertSame('Cancelled', $refreshed->hotel->room_statuses[0]['status']);
    }
}
