<?php

namespace Tests\Feature\TboHotel;

use App\Enums\BookingProduct;
use App\Enums\BookingStatus;
use App\Enums\Supplier;
use App\Jobs\BookHotelJob;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The last two things an agent touches: the button that takes the room, and the paper
 * the guest carries to the desk.
 */
class HotelVoucherTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Queue::fake();
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function booking(User $owner, BookingStatus $status = BookingStatus::Quoted, array $detail = []): Booking
    {
        $booking = Booking::create([
            'reference' => 'MT-VOUCHER',
            'product' => BookingProduct::Hotel,
            'supplier' => Supplier::TboHotel,
            'user_id' => $owner->id,
            'agency_id' => $owner->agency_id,
            'environment' => 'test',
            'status' => $status,
            'currency' => 'PHP',
            'total_amount' => '4036.02',
            'quote' => [
                'cancellationSchedule' => [
                    ['room' => null, 'from' => '2026-09-04 00:00:00', 'chargeType' => 'Fixed', 'charge' => 0.0],
                    ['room' => null, 'from' => '2026-09-09 00:00:00', 'chargeType' => 'Percentage', 'charge' => 100.0],
                ],
            ],
            'quote_raw' => [],
            'pax' => [
                ['title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Dela Cruz', 'type' => 'Adult', 'roomIndex' => 0, 'isLead' => true],
                ['title' => 'Mrs', 'firstName' => 'Ana', 'lastName' => 'Dela Cruz', 'type' => 'Adult', 'roomIndex' => 0, 'isLead' => false],
            ],
            'contact' => ['email' => 'agent@example.test', 'phone' => '+639171234567'],
        ]);

        $booking->hotel()->create(array_replace([
            'hotel_code' => '1012705',
            'hotel_name' => 'Jen s Comfy Home',
            'address' => 'Unit 208, Iceland Kassel Residences',
            'rating' => 3,
            'check_in' => '2026-09-11', 'check_out' => '2026-09-13',
            'nights' => 2, 'rooms_count' => 1, 'guest_nationality' => 'PH',
            'booking_code' => 'code', 'meal_type' => 'Room_Only',
            'room_names' => ['Standard Studio, 1 Queen Bed'],
            'rate_conditions' => ['<p>Photo ID required at check-in.</p>'],
            // Bucketed by room, which is the shape the column actually holds.
            'supplements' => ['all' => [
                ['type' => 'AtProperty', 'description' => 'Deposit Fee per stay', 'price' => 500.0, 'currency' => 'PHP'],
            ]],
        ], $detail));

        return $booking->fresh();
    }

    private function agent(array $permissions = ['hotel.book', 'booking.view']): User
    {
        $user = $this->userWith($permissions);
        $agency = Agency::factory()->create();
        Wallet::create(['agency_id' => $agency->id, 'currency' => 'PHP', 'balance' => '100000.00']);
        $user->forceFill(['agency_id' => $agency->id])->save();

        return $user->fresh();
    }

    // ---------------------------------------------------------------- confirm ----

    public function test_confirming_queues_the_book(): void
    {
        $user = $this->agent();
        $booking = $this->booking($user);

        $this->actingAs($user)
            ->post(route('hotels.bookings.book', $booking))
            ->assertRedirect();

        Queue::assertPushed(BookHotelJob::class,
            fn (BookHotelJob $job): bool => $job->bookingId === $booking->getKey());
    }

    public function test_confirming_needs_the_booking_permission(): void
    {
        $user = $this->agent(['booking.view']);

        $this->actingAs($user)
            ->post(route('hotels.bookings.book', $this->booking($user)))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    /**
     * Book is not idempotent, so a booking already sent must not be sent again by a
     * second press or a stale tab.
     */
    public function test_a_booking_already_sent_cannot_be_sent_again(): void
    {
        $user = $this->agent();
        $booking = $this->booking($user, BookingStatus::Processing);

        $this->actingAs($user)
            ->post(route('hotels.bookings.book', $booking))
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    /**
     * The route is the hotel one; a flight booking has its own chain.
     */
    public function test_the_route_refuses_a_flight_booking(): void
    {
        $user = $this->agent();
        $booking = $this->booking($user);
        $booking->forceFill(['product' => BookingProduct::Flight])->saveQuietly();

        $this->actingAs($user)
            ->post(route('hotels.bookings.book', $booking->fresh()))
            ->assertNotFound();
    }

    public function test_the_booking_page_offers_the_confirm_button(): void
    {
        $user = $this->agent();

        $this->actingAs($user)
            ->get(route('bookings.show', $this->booking($user)))
            ->assertOk()
            ->assertSee('Confirm with hotel')
            ->assertSee('Not yet confirmed');
    }

    public function test_an_agent_without_permission_is_not_offered_it(): void
    {
        $user = $this->agent(['booking.view']);

        $this->actingAs($user)
            ->get(route('bookings.show', $this->booking($user)))
            ->assertOk()
            ->assertDontSee('Confirm with hotel')
            ->assertSee('do not have permission to confirm');
    }

    /**
     * An unanswered Book leaves the booking here, and the page has to say so honestly
     * rather than show an ending it does not have.
     */
    public function test_a_booking_in_flight_says_it_is_unresolved(): void
    {
        $user = $this->agent();

        $this->actingAs($user)
            ->get(route('bookings.show', $this->booking($user, BookingStatus::Processing)))
            ->assertOk()
            ->assertSee('Confirming with the hotel')
            ->assertDontSee('Contacting the airline');
    }

    // ---------------------------------------------------------------- voucher ----

    /**
     * There is nothing to print until TBO has confirmed — before that it is a quote.
     */
    public function test_there_is_no_voucher_before_confirmation(): void
    {
        $user = $this->agent();

        $this->actingAs($user)
            ->get(route('hotels.bookings.voucher', $this->booking($user)))
            ->assertNotFound();
    }

    public function test_the_voucher_carries_what_the_desk_will_ask_for(): void
    {
        $user = $this->agent();
        $booking = $this->booking($user, BookingStatus::Confirmed, ['confirmation_number' => 'WM9CWM']);

        $this->actingAs($user)
            ->get(route('hotels.bookings.voucher', $booking))
            ->assertOk()
            ->assertSee('WM9CWM')
            ->assertSee('Jen s Comfy Home')
            ->assertSee('Standard Studio, 1 Queen Bed')
            ->assertSee('Mr Juan Dela Cruz')
            ->assertSee('lead guest')
            // What the guest still owes on arrival, and the terms that govern cancelling.
            ->assertSee('Payable at the hotel')
            ->assertSee('Deposit Fee per stay')
            ->assertSee('100% of the stay')
            ->assertSee('Photo ID required at check-in', false)
            ->assertSee('MT-VOUCHER');
    }

    /**
     * A test booking must never be mistaken for a real one in someone's hand.
     */
    public function test_a_test_voucher_says_so_on_its_face(): void
    {
        $user = $this->agent();
        $booking = $this->booking($user, BookingStatus::Confirmed, ['confirmation_number' => 'WM9CWM']);

        $this->actingAs($user)
            ->get(route('hotels.bookings.voucher', $booking))
            ->assertOk()
            ->assertSee('not a real booking');
    }

    /**
     * The hotel's own reference arrives late and only within thirty days of check-in,
     * so its absence is normal and must not print an empty label.
     */
    public function test_the_hotel_reference_appears_only_once_issued(): void
    {
        $user = $this->agent();

        $without = $this->booking($user, BookingStatus::Confirmed, ['confirmation_number' => 'WM9CWM']);
        $this->actingAs($user)->get(route('hotels.bookings.voucher', $without))
            ->assertOk()->assertDontSee('Hotel reference');

        $without->hotel->update(['hotel_confirmation_number' => 'HTL-778']);

        $this->actingAs($user)->get(route('hotels.bookings.voucher', $without->fresh()))
            ->assertOk()->assertSee('Hotel reference')->assertSee('HTL-778');
    }

    public function test_the_voucher_is_owned_and_gated(): void
    {
        $owner = $this->agent();
        $booking = $this->booking($owner, BookingStatus::Confirmed, ['confirmation_number' => 'WM9CWM']);

        $this->actingAs($this->agent())
            ->get(route('hotels.bookings.voucher', $booking))
            ->assertForbidden();
    }

    public function test_the_booking_page_links_to_the_voucher_once_confirmed(): void
    {
        $user = $this->agent();
        $booking = $this->booking($user, BookingStatus::Confirmed, ['confirmation_number' => 'WM9CWM']);

        $this->actingAs($user)
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('Print voucher')
            ->assertSee(route('hotels.bookings.voucher', $booking), false);
    }
}
