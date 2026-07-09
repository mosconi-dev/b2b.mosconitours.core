<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Booking\Exceptions\BookingException;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

class BookingTest extends TestCase
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

    private function fakeQuote(string $quoteFixture = 'farequote.json'): void
    {
        Http::fake([
            '*Authenticate*' => Http::response($this->fixture('authenticate.json'), 200),
            '*FareQuote*' => Http::response($this->fixture($quoteFixture), 200),
            '*SSR*' => Http::response($this->fixture('ssr.json'), 200),
        ]);
    }

    private function bookingUser(): User
    {
        return $this->userWith(['flight.view', 'flight.search', 'booking.view', 'booking.create']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'traceId' => 'trace-abc-123',
            'resultIndex' => str_repeat('R', 400),
            'contact' => ['email' => 'agent@example.com', 'phone' => '09170000000'],
            'passengers' => [
                ['type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz', 'gender' => 'M'],
            ],
        ], $overrides);
    }

    public function test_create_renders_the_wizard(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->get(route('bookings.create', [
                'traceId' => 'trace-abc-123',
                'resultIndex' => 'OB1',
                'oldFare' => 6000,
                'search' => 'Manila (MNL) → Cebu (CEB) · 1 Pax',
            ]))
            ->assertOk()
            ->assertSee('Guest Details')                 // stepper labels
            ->assertSee('Payment')
            ->assertSee('Confirmation')
            ->assertSee('Fare price updated')            // the in-wizard price-change gate
            ->assertSee('Manila (MNL) → Cebu (CEB)');    // the carried search-context bar
    }

    public function test_create_embeds_the_editable_search_form(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->get(route('bookings.create', [
                'traceId' => 'trace-abc-123',
                'resultIndex' => 'OB1',
                'search' => 'Manila (MNL) → Cebu (CEB) · 1 Pax',
                'q' => 'ENCODED_SEARCH_TOKEN',
            ]))
            ->assertOk()
            // The in-place "Edit search" form is embedded, pre-filled from the
            // token, and configured to hand off to the Select Flight page.
            ->assertSee('ENCODED_SEARCH_TOKEN')
            ->assertSee("redirectUrl: '".route('flights')."'", false);
    }

    public function test_create_requires_booking_create_permission(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->userWith(['booking.view']))
            ->get(route('bookings.create', ['traceId' => 'x', 'resultIndex' => 'y']))
            ->assertForbidden();
    }

    public function test_create_redirects_to_search_when_the_fare_is_unavailable(): void
    {
        Http::fake([
            '*Authenticate*' => Http::response($this->fixture('authenticate.json'), 200),
            '*FareQuote*' => Http::response('', 504),
        ]);

        $this->actingAs($this->bookingUser())
            ->get(route('bookings.create', ['traceId' => 'x', 'resultIndex' => 'y']))
            ->assertRedirect(route('flights'));
    }

    public function test_store_requires_booking_create_permission(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->userWith(['booking.view']))
            ->post(route('bookings.store'), $this->payload())
            ->assertForbidden();
    }

    public function test_store_creates_a_quoted_booking_from_a_fresh_quote(): void
    {
        $this->fakeQuote();
        $user = $this->bookingUser();

        $this->actingAs($user)
            ->post(route('bookings.store'), $this->payload())
            ->assertRedirect();

        $booking = Booking::firstOrFail();
        $this->assertSame($user->id, $booking->user_id);
        $this->assertSame(BookingStatus::Quoted, $booking->status);
        $this->assertSame('test', $booking->environment);
        $this->assertTrue($booking->is_lcc);
        $this->assertEqualsWithDelta(6400, (float) $booking->total_amount, 0.001);
        $this->assertCount(1, $booking->pax);
        $this->assertStringStartsWith('MT-', $booking->reference);
    }

    public function test_store_enforces_passport_when_the_fare_requires_it(): void
    {
        $this->fakeQuote('farequote-passport.json'); // IsPassportRequiredAtBook = true

        $this->actingAs($this->bookingUser())
            ->post(route('bookings.store'), $this->payload()) // no passport details
            ->assertSessionHasErrors('booking');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_store_accepts_passengers_with_passport_when_required(): void
    {
        $this->fakeQuote('farequote-passport.json');

        $this->actingAs($this->bookingUser())
            ->post(route('bookings.store'), $this->payload([
                'passengers' => [[
                    'type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz',
                    'passportNo' => 'P1234567', 'passportExpiry' => '2030-01-01', 'nationality' => 'PH',
                ]],
            ]))
            ->assertRedirect();

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_store_returns_a_json_redirect_for_xhr(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->postJson(route('bookings.store'), $this->payload())
            ->assertOk()
            ->assertJsonStructure(['redirect', 'reference']);

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_store_json_returns_422_when_passport_missing(): void
    {
        $this->fakeQuote('farequote-passport.json');

        $this->actingAs($this->bookingUser())
            ->postJson(route('bookings.store'), $this->payload()) // no passport
            ->assertStatus(422);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_store_folds_selected_ancillaries_into_the_total(): void
    {
        $this->fakeQuote(); // LCC fare + SSR options

        $this->actingAs($this->bookingUser())
            ->post(route('bookings.store'), $this->payload([
                'passengers' => [[
                    'type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz',
                    'baggage' => 'PBAG20', 'meal' => 'HFML',
                ]],
            ]))
            ->assertRedirect();

        $booking = Booking::firstOrFail();
        $this->assertEqualsWithDelta(1550, (float) $booking->ancillary_total, 0.001); // 1200 + 350
        $this->assertEqualsWithDelta(7950, (float) $booking->total_amount, 0.001);     // 6400 + 1550
        $this->assertSame('PBAG20', data_get($booking->pax, '0.ssr.baggage.code'));
        $this->assertSame('HFML', data_get($booking->pax, '0.ssr.meal.code'));
    }

    public function test_store_rejects_extra_baggage_for_an_infant(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->post(route('bookings.store'), $this->payload([
                'passengers' => [[
                    'type' => 'Infant', 'title' => 'Mstr', 'firstName' => 'Baby', 'lastName' => 'Cruz',
                    'baggage' => 'PBAG20',
                ]],
            ]))
            ->assertSessionHasErrors('booking');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_store_validates_the_input(): void
    {
        $this->actingAs($this->bookingUser())
            ->postJson(route('bookings.store'), ['passengers' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['traceId', 'resultIndex', 'passengers', 'contact.email']);
    }

    public function test_index_shows_only_the_users_own_bookings(): void
    {
        $user = $this->bookingUser();
        Booking::factory()->create(['user_id' => $user->id, 'reference' => 'MT-MINE0001']);
        Booking::factory()->create(['reference' => 'MT-OTHER001']);

        $this->actingAs($user)
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee('MT-MINE0001')
            ->assertDontSee('MT-OTHER001');
    }

    public function test_show_renders_the_users_booking(): void
    {
        $user = $this->bookingUser();
        $booking = Booking::factory()->create(['user_id' => $user->id, 'reference' => 'MT-SHOW0001']);

        $this->actingAs($user)
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('MT-SHOW0001')
            ->assertSee('Passengers');
    }

    public function test_show_forbids_another_users_booking(): void
    {
        $other = Booking::factory()->create();

        $this->actingAs($this->bookingUser())
            ->get(route('bookings.show', $other))
            ->assertForbidden();
    }

    public function test_index_requires_booking_view(): void
    {
        $this->actingAs($this->userWith(['flight.view']))
            ->get(route('bookings.index'))
            ->assertForbidden();
    }

    public function test_environment_is_immutable_after_creation(): void
    {
        $booking = Booking::factory()->create(['environment' => 'test']);

        $this->expectException(RuntimeException::class);
        $booking->update(['environment' => 'live']);
    }

    public function test_service_refuses_an_illegal_transition(): void
    {
        $booking = Booking::factory()->status(BookingStatus::Ticketed)->create();

        $this->expectException(BookingException::class);
        app(BookingService::class)->transitionTo($booking, BookingStatus::Quoted);
    }

    public function test_service_allows_a_legal_transition_with_attributes(): void
    {
        $booking = Booking::factory()->status(BookingStatus::Quoted)->create();

        app(BookingService::class)->transitionTo($booking, BookingStatus::Booked, ['pnr' => 'ABC123']);

        $this->assertSame(BookingStatus::Booked, $booking->fresh()->status);
        $this->assertSame('ABC123', $booking->fresh()->pnr);
    }
}
