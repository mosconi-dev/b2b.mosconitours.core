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

    private int $issued = 0;

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
            // Numbered, because a test may need two bookings and the column is unique.
            'reference' => 'MT-VOUCHER'.(++$this->issued > 1 ? $this->issued : ''),
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

    // --------------------------------------------------------------- recovery ----
    //
    // The wizard sends the Book itself. What is left here is the stranded case: a stay
    // charged for and saved whose job never ran.

    public function test_sending_a_stranded_booking_queues_the_book(): void
    {
        $user = $this->agent();
        $booking = $this->booking($user);

        $this->actingAs($user)
            ->post(route('hotels.bookings.book', $booking))
            ->assertRedirect();

        // Marked before the dispatch, exactly as the wizard does it.
        $this->assertSame(BookingStatus::Processing, $booking->fresh()->status);

        Queue::assertPushed(BookHotelJob::class,
            fn (BookHotelJob $job): bool => $job->bookingId === $booking->getKey());
    }

    /**
     * The one thing that must never happen. A Book already on the wire is settled by
     * reading the reference back, so a booking that carries a send time is refused
     * whatever its status says.
     */
    public function test_a_booking_already_on_the_wire_is_never_sent_again(): void
    {
        $user = $this->agent();
        $booking = $this->booking($user);
        $booking->hotel->update(['book_sent_at' => now()]);

        $this->actingAs($user)
            ->post(route('hotels.bookings.book', $booking->fresh()))
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
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

    public function test_the_booking_page_offers_to_send_a_stranded_stay(): void
    {
        $user = $this->agent();

        $this->actingAs($user)
            ->get(route('bookings.show', $this->booking($user)))
            ->assertOk()
            ->assertSee('Send to hotel')
            // Worded as the fault it is, not as a step the agent forgot to take.
            ->assertSee('Not sent to the hotel')
            ->assertSee('no room is being held');
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
            ->assertSee('MR JUAN DELA CRUZ')
            ->assertSee('lead')
            // What the guest still owes on arrival, and the terms that govern cancelling.
            ->assertSee('Payable at the hotel')
            ->assertSee('Deposit Fee per stay')
            ->assertSee('100%')
            ->assertSee('of the stay')
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
            ->assertSee('NOT A REAL BOOKING');
    }

    /**
     * The hotel's own reference arrives late and only within thirty days of check-in,
     * so the reference band has to distinguish "not issued yet" from "we lost it".
     */
    public function test_the_hotel_reference_reads_as_pending_until_it_is_issued(): void
    {
        $user = $this->agent();

        $booking = $this->booking($user, BookingStatus::Confirmed, ['confirmation_number' => 'WM9CWM']);
        $this->actingAs($user)->get(route('hotels.bookings.voucher', $booking))
            ->assertOk()
            ->assertSee('Hotel reference')
            ->assertSee('—');

        $booking->hotel->update(['hotel_confirmation_number' => 'HTL-778']);

        $this->actingAs($user)->get(route('hotels.bookings.voucher', $booking->fresh()))
            ->assertOk()->assertSee('Hotel reference')->assertSee('HTL-778');
    }

    // ------------------------------------------------- the shape of the document ----

    /**
     * The guest deals with the agency, not with us and not with TBO. Both printed
     * documents carry the agency's masthead for the same reason.
     */
    public function test_the_voucher_is_issued_in_the_agency_name(): void
    {
        $user = $this->agent();
        $agency = $user->agency;
        $agency->update(['contact_email' => 'desk@agency.test', 'contact_phone' => '+63 2 8000 0000']);

        $booking = $this->booking($user, BookingStatus::Confirmed, ['confirmation_number' => 'WM9CWM']);

        $this->actingAs($user)
            ->get(route('hotels.bookings.voucher', $booking))
            ->assertOk()
            ->assertSee($agency->name)
            ->assertSee('desk@agency.test')
            ->assertSee('+63 2 8000 0000')
            ->assertSee('Need help with this booking?');
    }

    /**
     * The same switch the e-ticket carries: what the agency paid is not the guest's
     * business, and the copy handed over must not print it.
     */
    public function test_the_guest_copy_hides_the_rate(): void
    {
        $user = $this->agent();
        $booking = $this->booking($user, BookingStatus::Confirmed, ['confirmation_number' => 'WM9CWM']);

        $this->actingAs($user)
            ->get(route('hotels.bookings.voucher', $booking))
            ->assertOk()
            ->assertSee('Rate summary')
            ->assertSee('4,036.02');

        $this->actingAs($user)
            ->get(route('hotels.bookings.voucher', [$booking, 'prices' => 0]))
            ->assertOk()
            ->assertDontSee('Rate summary')
            ->assertDontSee('4,036.02')
            // What the guest still owes is not the agency's rate, and stays.
            ->assertSee('Deposit Fee per stay');
    }

    /**
     * A voucher is a document people keep, and the printed copy does not update when
     * the booking does. A cancelled stay has to say so on its face.
     */
    public function test_a_cancelled_stay_says_the_voucher_is_dead(): void
    {
        $user = $this->agent();
        $booking = $this->booking($user, BookingStatus::Cancelled, ['confirmation_number' => 'WM9CWM']);

        $this->actingAs($user)
            ->get(route('hotels.bookings.voucher', $booking))
            ->assertOk()
            ->assertSee('no longer valid');

        $this->actingAs($user)
            ->get(route('hotels.bookings.voucher', $this->booking(
                $user, BookingStatus::Confirmed, ['confirmation_number' => 'WM9CWM'],
            )))
            ->assertOk()
            ->assertDontSee('no longer valid');
    }

    /**
     * The desk hours are stated inside the hotel's own small print. A guest arriving at
     * 8am needs them at the top, not buried on page two.
     */
    public function test_the_desk_hours_are_lifted_out_of_the_small_print(): void
    {
        $user = $this->agent();
        $booking = $this->booking($user, BookingStatus::Confirmed, [
            'confirmation_number' => 'WM9CWM',
            'rate_conditions' => [
                '<p>CheckIn Time-Begin: 2:00 PM</p>',
                '<p>CheckIn Time-End: 6:00 PM</p>',
                '<p>CheckOut Time: 12:00 PM</p>',
            ],
        ]);

        $this->actingAs($user)
            ->get(route('hotels.bookings.voucher', $booking))
            ->assertOk()
            ->assertSee('from 2:00 PM until 6:00 PM')
            ->assertSee('by 12:00 PM');
    }

    /**
     * A property that does not state its hours must not have any invented for it.
     */
    public function test_absent_desk_hours_are_simply_not_printed(): void
    {
        $user = $this->agent();
        $booking = $this->booking($user, BookingStatus::Confirmed, [
            'confirmation_number' => 'WM9CWM',
            'rate_conditions' => ['<p>Photo ID required at check-in.</p>'],
        ]);

        $this->actingAs($user)
            ->get(route('hotels.bookings.voucher', $booking))
            ->assertOk()
            ->assertDontSee('from ')
            ->assertDontSee('by 12');
    }

    /**
     * Every room the property is holding belongs on the rooming list, including one
     * whose guest names never made it into the booking.
     */
    public function test_a_room_without_names_still_appears(): void
    {
        $user = $this->agent();
        $booking = $this->booking($user, BookingStatus::Confirmed, [
            'confirmation_number' => 'WM9CWM',
            'rooms_count' => 2,
            'room_names' => ['Standard Studio, 1 Queen Bed', 'Deluxe Room, 2 Twin Beds'],
        ]);

        $this->actingAs($user)
            ->get(route('hotels.bookings.voucher', $booking))
            ->assertOk()
            ->assertSee('Deluxe Room, 2 Twin Beds');
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

    /**
     * The per-night rate on the voucher is OURS, not the supplier's.
     *
     * The stored quote keeps TBO's own nightly figure, and it is the net. Printed on a
     * document an agency holds, it multiplies straight back up to our cost — so the
     * voucher recomputes it from what the agency was actually charged.
     */
    public function test_the_nightly_rate_is_recomputed_from_the_selling_total(): void
    {
        $user = $this->agent();
        $booking = $this->booking($user, BookingStatus::Confirmed, ['confirmation_number' => 'WM9CWM']);

        // TBO's own per-night net for the two-night stay, well below what we charged.
        $booking->update(['quote' => $booking->quote + ['nightlyRate' => 1500.00]]);

        $this->actingAs($user)
            ->get(route('hotels.bookings.voucher', $booking))
            ->assertOk()
            ->assertSee('2,018.01')     // 4,036.02 over two nights, one room
            ->assertDontSee('1,500.00') // TBO's figure
            ->assertDontSee('3,000.00'); // and what it multiplies back up to
    }

    /** An unevenly priced stay keeps the supplier's null rather than inventing a rate. */
    public function test_no_nightly_rate_is_printed_when_the_supplier_gave_none(): void
    {
        $user = $this->agent();
        $booking = $this->booking($user, BookingStatus::Confirmed, ['confirmation_number' => 'WM9CWM']);

        $this->actingAs($user)
            ->get(route('hotels.bookings.voucher', $booking))
            ->assertOk()
            ->assertDontSee('per room per night');
    }
}
