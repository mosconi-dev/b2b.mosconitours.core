<?php

namespace Tests\Feature;

use App\Enums\BookingProduct;
use App\Enums\BookingStatus;
use App\Enums\Supplier;
use App\Models\Booking;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The recent-bookings panel the flight and hotel search pages show above the form.
 *
 * It is a shortcut back into work already done, so what matters is that it is the
 * agent's own recent work, for the product whose page they are on, and short enough
 * to read at a glance.
 */
class RecentBookingsTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function agent(): User
    {
        return $this->userWith(['flight.view', 'hotel.view', 'booking.view']);
    }

    private function flight(User $user, array $attributes = []): Booking
    {
        return Booking::factory()->create(array_replace([
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'reference' => 'MT-FLIGHT01',
            'status' => BookingStatus::Ticketed,
            'quote' => [
                'trips' => [[
                    'direction' => 'outbound',
                    'segments' => [
                        ['origin' => ['code' => 'MNL'], 'destination' => ['code' => 'CEB']],
                    ],
                ]],
            ],
        ], $attributes));
    }

    private function hotel(User $user, array $attributes = [], array $stay = []): Booking
    {
        $booking = Booking::factory()->create(array_replace([
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'reference' => 'MT-HOTEL001',
            'product' => BookingProduct::Hotel,
            'supplier' => Supplier::TboHotel,
            'status' => BookingStatus::Confirmed,
            'quote' => [],
        ], $attributes));

        $booking->hotel()->create(array_replace([
            'hotel_code' => '1012705', 'hotel_name' => 'Jen s Comfy Home', 'city' => 'Cebu',
            'check_in' => '2026-09-11', 'check_out' => '2026-09-13',
            'nights' => 2, 'rooms_count' => 1, 'guest_nationality' => 'PH',
            'booking_code' => 'code', 'confirmation_number' => 'WM9CWM',
        ], $stay));

        return $booking->fresh();
    }

    // ------------------------------------------------------------ what it shows ----

    public function test_the_flights_page_shows_the_users_latest_flight_bookings(): void
    {
        $user = $this->agent();

        // Five, oldest first, so the one that must fall off the end is unambiguous.
        foreach (range(5, 1) as $n) {
            $this->flight($user, [
                'reference' => "MT-FLIGHT0{$n}",
                'created_at' => now()->subDays($n),
            ]);
        }

        $response = $this->actingAs($user)->get(route('flights'))->assertOk();

        $response->assertSee('Recent bookings')
            ->assertSee('MT-FLIGHT01')
            ->assertSee('MT-FLIGHT02')
            ->assertSee('MT-FLIGHT03')
            ->assertSee('MT-FLIGHT04')
            ->assertDontSee('MT-FLIGHT05');

        // What was bought, and where it leads.
        $response->assertSee('MNL → CEB', escape: false)
            ->assertSee(route('bookings.index', ['product' => 'flight']), escape: false);
    }

    public function test_the_hotels_page_shows_the_users_latest_hotel_bookings(): void
    {
        $user = $this->agent();

        foreach (range(5, 1) as $n) {
            $this->hotel($user, [
                'reference' => "MT-HOTEL00{$n}",
                'created_at' => now()->subDays($n),
            ]);
        }

        $response = $this->actingAs($user)->get(route('hotels'))->assertOk();

        $response->assertSee('Recent bookings')
            ->assertSee('MT-HOTEL001')
            ->assertSee('MT-HOTEL004')
            ->assertDontSee('MT-HOTEL005')
            // The property is on the detail row, not the spine.
            ->assertSee('Jen s Comfy Home');
    }

    /**
     * Each page is about one product, so the other product's bookings are noise —
     * and a hotel row on the flights page would link to a wizard that cannot open it.
     */
    public function test_each_page_shows_only_its_own_product(): void
    {
        $user = $this->agent();
        $this->flight($user);
        $this->hotel($user);

        $this->actingAs($user)->get(route('flights'))->assertOk()
            ->assertSee('MT-FLIGHT01')
            ->assertDontSee('MT-HOTEL001');

        $this->actingAs($user)->get(route('hotels'))->assertOk()
            ->assertSee('MT-HOTEL001')
            ->assertDontSee('MT-FLIGHT01');
    }

    public function test_it_never_shows_another_users_bookings(): void
    {
        $user = $this->agent();
        Booking::factory()->create(['reference' => 'MT-THEIRS01']);

        $this->actingAs($user)->get(route('flights'))->assertOk()->assertDontSee('MT-THEIRS01');
    }

    /**
     * Every row is a link into /bookings. Showing the panel to someone who would be
     * refused there is a panel of 403s.
     */
    public function test_it_is_hidden_from_a_user_who_may_not_open_a_booking(): void
    {
        $user = $this->userWith(['flight.view']);
        $this->flight($user);

        $this->actingAs($user)->get(route('flights'))->assertOk()
            ->assertDontSee('Recent bookings')
            ->assertDontSee('MT-FLIGHT01');
    }

    public function test_the_panel_is_absent_until_there_is_a_booking(): void
    {
        $this->actingAs($this->agent())->get(route('flights'))->assertOk()
            ->assertDontSee('Recent bookings');
    }

    // ------------------------------------------------------------- who it is for ----

    /**
     * The lead guest is who the front desk asks for, and TBO Hotel flags them — the
     * panel must name that guest rather than whoever was typed in first.
     */
    public function test_a_hotel_row_names_the_flagged_lead_guest(): void
    {
        $user = $this->agent();
        $this->hotel($user, ['pax' => [
            ['type' => 'Child', 'title' => 'Mr', 'firstName' => 'Pedro', 'lastName' => 'Santos', 'isLead' => false],
            ['type' => 'Adult', 'title' => 'Mrs', 'firstName' => 'Maria', 'lastName' => 'Santos', 'isLead' => true],
        ]]);

        $this->actingAs($user)->get(route('hotels'))->assertOk()
            ->assertSee('Mrs Maria Santos')
            ->assertSee('+1 more');
    }

    /**
     * The air API has no lead flag, so the order captured in the wizard is the only
     * thing that says who the booking is under.
     */
    public function test_a_flight_row_names_the_first_passenger(): void
    {
        $user = $this->agent();
        $this->flight($user, ['pax' => [
            ['type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Dela Cruz'],
            ['type' => 'Adult', 'title' => 'Ms', 'firstName' => 'Anna', 'lastName' => 'Reyes'],
        ]]);

        $this->actingAs($user)->get(route('flights'))->assertOk()
            ->assertSee('Mr Juan Dela Cruz')
            ->assertSee('+1 more');
    }

    public function test_a_booking_with_no_travellers_captured_has_no_lead(): void
    {
        $user = $this->agent();

        $this->assertNull($this->flight($user, ['pax' => []])->leadPassengerName());
        // A traveller carrying no name at all is the same absence, not a blank row.
        $this->assertNull(
            $this->flight($user, ['reference' => 'MT-FLIGHT02', 'pax' => [['type' => 'Adult']]])->leadPassengerName()
        );
    }
}
