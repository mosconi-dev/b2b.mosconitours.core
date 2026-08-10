<?php

namespace Tests\Feature\TboAir;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The HTTP surface of the money step. Every supplier call is faked.
 */
class TicketingRoutesTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tboair/{$name}")), true);
    }

    private function fake(array $overrides = []): void
    {
        Http::fake(array_merge([
            '*Authenticate*' => Http::response($this->fixture('authenticate.json'), 200),
            '*GetAvailableBalance*' => Http::response($this->fixture('balance.json'), 200),
            '*Booking/Book*' => Http::response(['PNR' => 'QWER12', 'BookingId' => 884213, 'Status' => 1], 200),
            '*Booking/Ticket*' => Http::response(['PNR' => 'QWER12', 'BookingId' => 884213, 'Status' => 1], 200),
            '*GetBookingDetails*' => Http::response($this->fixture('bookingdetails.json'), 200),
        ], $overrides));
    }

    private function booking(User $user, array $overrides = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'environment' => 'test',
            'status' => BookingStatus::Quoted,
            'is_lcc' => true,
            'total_amount' => '6400.00',
            'trace_id' => 'trace-abc-123',
            'result_index' => str_repeat('R', 40),
            'quote_raw' => $this->fixture('farequote.json'),
            'seats_available' => [9],
            'pax' => [[
                'type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz',
                'gender' => 'M', 'isLeadPax' => true, 'countryCode' => 'PH', 'countryName' => 'Philippines',
            ]],
        ], $overrides));
    }

    private function agent(array $extra = []): User
    {
        return $this->userWith(array_merge(['booking.view', 'booking.create'], $extra));
    }

    // ---- Permissions ------------------------------------------------------

    public function test_issuing_requires_the_issue_permission(): void
    {
        $this->fake();
        $user = $this->agent();

        $this->actingAs($user)
            ->post(route('bookings.issue', $this->booking($user)))
            ->assertForbidden();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'Booking/Ticket'));
    }

    public function test_holding_requires_the_book_permission(): void
    {
        $this->fake();
        $user = $this->agent(['flight.issue']); // issue alone must not grant hold

        $this->actingAs($user)
            ->post(route('bookings.book', $this->booking($user, ['is_lcc' => false])))
            ->assertForbidden();
    }

    /**
     * Holding requires the ability to ticket as well. A PNR nobody here is allowed to
     * issue just occupies airline seats until someone releases it.
     */
    public function test_holding_also_requires_the_issue_permission(): void
    {
        $this->fake();
        $user = $this->agent(['flight.book']); // can hold, cannot ticket

        $this->actingAs($user)
            ->post(route('bookings.book', $this->booking($user, ['is_lcc' => false])))
            ->assertForbidden();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'Booking/Book'));
    }

    public function test_the_hold_button_is_hidden_without_the_issue_permission(): void
    {
        $this->fake();
        $user = $this->agent(['flight.book']);

        $booking = $this->booking($user, ['is_lcc' => false]);

        // The explanatory copy mentions "Hold PNR" either way, so assert on the form
        // target rather than the words.
        $this->actingAs($user)
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertDontSee(route('bookings.book', $booking))
            ->assertSee('do not have permission to hold');
    }

    public function test_an_agent_cannot_ticket_someone_elses_booking(): void
    {
        $this->fake();
        $owner = $this->agent();
        $other = $this->agent(['flight.issue']);

        $this->actingAs($other)
            ->post(route('bookings.issue', $this->booking($owner)))
            ->assertForbidden();
    }

    // ---- The happy path ---------------------------------------------------

    public function test_an_agent_with_permission_issues_a_ticket(): void
    {
        $this->fake();
        $user = $this->agent(['flight.issue']);
        $booking = $this->booking($user);

        $this->actingAs($user)
            ->post(route('bookings.issue', $booking))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $s): bool => str_contains($s, 'ticketed'));

        $this->assertSame(BookingStatus::Ticketed, $booking->fresh()->status);
    }

    public function test_a_non_lcc_is_held_then_issued(): void
    {
        $this->fake();
        $user = $this->agent(['flight.book', 'flight.issue']);
        $booking = $this->booking($user, ['is_lcc' => false]);

        $this->actingAs($user)->post(route('bookings.book', $booking))->assertRedirect();
        $this->assertSame(BookingStatus::Booked, $booking->fresh()->status);

        $this->actingAs($user)->post(route('bookings.issue', $booking))->assertRedirect();
        $this->assertSame(BookingStatus::Ticketed, $booking->fresh()->status);
    }

    // ---- Failure reporting ------------------------------------------------

    public function test_a_domain_refusal_is_shown_without_changing_the_booking(): void
    {
        $this->fake();
        $user = $this->agent(['flight.issue']);
        $booking = $this->booking($user, ['is_lcc' => false]); // quoted non-LCC has no PNR

        $this->actingAs($user)
            ->post(route('bookings.issue', $booking))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $s): bool => str_contains($s, 'cannot be ticketed'));

        $this->assertSame(BookingStatus::Quoted, $booking->fresh()->status);
    }

    /**
     * A timeout may mean the request landed. The agent must be told to check, never
     * invited to retry.
     */
    public function test_a_timeout_warns_that_the_booking_may_exist(): void
    {
        $this->fake([
            '*Booking/Ticket*' => fn () => throw new ConnectionException('timed out'),
        ]);

        $user = $this->agent(['flight.issue']);

        $this->actingAs($user)
            ->post(route('bookings.issue', $this->booking($user)))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $s): bool => str_contains($s, 'may still have been created'));
    }

    // ---- The page ---------------------------------------------------------

    public function test_the_page_offers_issue_to_a_permitted_agent(): void
    {
        $this->fake();
        $user = $this->agent(['flight.issue']);

        $this->actingAs($user)
            ->get(route('bookings.show', $this->booking($user)))
            ->assertOk()
            ->assertSee('Issue ticket');
    }

    public function test_the_page_hides_issue_without_permission(): void
    {
        $this->fake();
        $user = $this->agent();

        $this->actingAs($user)
            ->get(route('bookings.show', $this->booking($user)))
            ->assertOk()
            ->assertDontSee('Issue ticket');
    }

    public function test_a_ticketed_booking_offers_nothing_further(): void
    {
        $this->fake();
        $user = $this->agent(['flight.book', 'flight.issue']);

        $this->actingAs($user)
            ->get(route('bookings.show', $this->booking($user, ['status' => BookingStatus::Ticketed])))
            ->assertOk()
            ->assertDontSee('Issue ticket')
            ->assertDontSee('Hold PNR');
    }

    /**
     * The page carried "Ticketing (Book / Ticket) is not enabled yet" from before
     * Phase 4.1 and kept saying it underneath a working Issue button.
     */
    public function test_the_page_does_not_claim_ticketing_is_unavailable(): void
    {
        $this->fake();
        $user = $this->agent(['flight.issue']);

        $this->actingAs($user)
            ->get(route('bookings.show', $this->booking($user)))
            ->assertOk()
            ->assertDontSee('not enabled');
    }

    public function test_a_live_booking_is_flagged_before_issuing(): void
    {
        $this->fake();
        $user = $this->agent(['flight.issue']);

        $this->actingAs($user)
            ->get(route('bookings.show', $this->booking($user, ['environment' => 'live'])))
            ->assertOk()
            ->assertSee('This is a LIVE booking', false);
    }

    public function test_an_lcc_is_not_offered_a_hold(): void
    {
        $this->fake();
        $user = $this->agent(['flight.book', 'flight.issue']);

        $booking = $this->booking($user, ['is_lcc' => true]);

        $this->actingAs($user)
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertDontSee(route('bookings.book', $booking));
    }
}
