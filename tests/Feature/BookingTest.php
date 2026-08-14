<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Jobs\FulfilBookingJob;
use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Booking\Exceptions\BookingException;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

        // Completing the wizard now queues Book → Ticket. These tests are about what
        // gets *saved*, so the job is recorded rather than run — otherwise every one of
        // them would also be a ticketing test, against unfaked supplier calls.
        Queue::fake();
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
        return $this->userWith(['flight.view', 'flight.search', 'booking.view', 'booking.create', 'flight.issue']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'traceId' => 'trace-abc-123',
            'resultIndex' => str_repeat('R', 400),
            'contact' => [
                'email' => 'agent@example.com',
                'phone' => '09170000000',
                'mobileCountryCode' => '63',
                'addressLine1' => '123 Rizal Street',
                'city' => 'Makati',
                'countryCode' => 'PH',
            ],
            'passengers' => [
                ['type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz', 'gender' => 'M', 'dateOfBirth' => '1990-08-15'],
            ],
        ], $overrides);
    }

    /**
     * Where the flight wizard lives, asserted on the path rather than the name.
     *
     * Picking a fare must not drop the agent onto a generic /bookings/create — until
     * there is a booking, the URL says what is being booked, the same way the hotel
     * wizard sits on /hotels/book. Every other test here resolves these by route
     * name, so this is the only thing holding the paths themselves.
     */
    public function test_the_flight_booking_steps_live_under_the_flights_prefix(): void
    {
        $this->assertSame('/flights/book', route('flights.book', absolute: false));
        $this->assertSame('/flights/bookings', route('flights.bookings.store', absolute: false));
        $this->assertSame('/flights/bookings/7/fulfil', route('flights.bookings.fulfil', 7, absolute: false));

        // And nothing answers at the old generic path.
        $this->actingAs($this->bookingUser())->get('/bookings/create')->assertNotFound();
    }

    public function test_create_renders_the_wizard(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->get(route('flights.book', [
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

    /**
     * Completing the wizard issues a real ticket and spends real money, and it is the
     * only press that does. The warning belongs at that press, not on a later page the
     * agent has no reason to open.
     */
    public function test_the_wizard_warns_before_a_live_ticket(): void
    {
        $this->fakeQuote();
        config(['tboair.default' => 'live']);

        $this->actingAs($this->bookingUser())
            ->get(route('flights.book', ['traceId' => 'trace-abc-123', 'resultIndex' => 'OB1']))
            ->assertOk()
            ->assertSee('This is a LIVE booking.')
            ->assertSee('issues a real ticket with the airline');
    }

    /**
     * And says nothing on test. A warning shown on every press is one nobody reads on
     * the day it counts.
     */
    public function test_the_wizard_does_not_cry_wolf_on_test(): void
    {
        $this->fakeQuote();
        config(['tboair.default' => 'test']);

        $this->actingAs($this->bookingUser())
            ->get(route('flights.book', ['traceId' => 'trace-abc-123', 'resultIndex' => 'OB1']))
            ->assertOk()
            ->assertDontSee('This is a LIVE booking.');
    }

    /**
     * The wizard's summary card can expand into the same leg-by-leg itinerary the
     * results page shows, plus the fare conditions — so a client re-checking the
     * flight doesn't have to go back and lose the wizard.
     */
    public function test_create_renders_the_expandable_flight_details(): void
    {
        $this->fakeQuote();

        $res = $this->actingAs($this->bookingUser())
            ->get(route('flights.book', ['traceId' => 'trace-abc-123', 'resultIndex' => 'OB1']))
            ->assertOk()
            ->assertSee('Flight details')
            ->assertSee('Fare conditions');

        // The itinerary and fee summary ride along on the injected quote — no
        // second provider call from the page.
        $content = $res->getContent();
        $this->assertStringContainsString('5J561', $content);
        $this->assertStringContainsString('25 KG', $content);
        $this->assertStringContainsString('4466 PHP (Before)', $content);
    }

    public function test_create_embeds_the_editable_search_form(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->get(route('flights.book', [
                'traceId' => 'trace-abc-123',
                'resultIndex' => 'OB1',
                'search' => 'Manila (MNL) → Cebu (CEB) · 1 Pax',
                'q' => 'ENCODED_SEARCH_TOKEN',
            ]))
            ->assertOk()
            // The in-place "Modify" form is embedded, pre-filled from the
            // token, and configured to hand off to the Select Flight page.
            ->assertSee('ENCODED_SEARCH_TOKEN')
            ->assertSee("redirectUrl: '".route('flights')."'", false);
    }

    /**
     * Add-ons are a summary card per passenger that opens a picker — not a dropdown,
     * and not a wall of tiles that becomes unreadable at six guests.
     */
    public function test_create_renders_addons_as_cards_with_a_picker(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->get(route('flights.book', ['traceId' => 'trace-abc-123', 'resultIndex' => 'OB1']))
            ->assertOk()
            ->assertSee('Checked baggage')
            ->assertSee('Add-ons total')
            ->assertSee('openAddOnPicker(', escape: false)     // the card opens the dialog
            ->assertSee('confirmAddOnPicker(', escape: false)  // Select commits the draft
            ->assertSee('cancelAddOnPicker(', escape: false)   // Cancel discards it
            ->assertSee('role="dialog"', escape: false)
            ->assertSee('role="radiogroup"', escape: false)
            // One tab per leg: TBO prices add-ons per leg, and on a connection it
            // sells meals per flight but baggage for the whole direction.
            ->assertSee('role="tab"', escape: false)
            ->assertSee('addOnPickerTabs', escape: false)
            ->assertSee('addOnPickerActiveOptions', escape: false)
            ->assertDontSee('<option value="">No extra baggage', false); // the old select is gone
    }

    public function test_create_requires_booking_create_permission(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->userWith(['booking.view']))
            ->get(route('flights.book', ['traceId' => 'x', 'resultIndex' => 'y']))
            ->assertForbidden();
    }

    public function test_create_redirects_to_search_when_the_fare_is_unavailable(): void
    {
        Http::fake([
            '*Authenticate*' => Http::response($this->fixture('authenticate.json'), 200),
            '*FareQuote*' => Http::response('', 504),
        ]);

        $this->actingAs($this->bookingUser())
            ->get(route('flights.book', ['traceId' => 'x', 'resultIndex' => 'y']))
            ->assertRedirect(route('flights'));
    }

    public function test_store_requires_booking_create_permission(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->userWith(['booking.view']))
            ->post(route('flights.bookings.store'), $this->payload())
            ->assertForbidden();
    }

    /**
     * Completing the wizard is the whole transaction, so the booking lands as
     * `processing` with Book → Ticket already queued — not as a quote waiting for
     * someone to press a second button.
     */
    public function test_store_creates_a_processing_booking_and_queues_the_ticket(): void
    {
        $this->fakeQuote();
        $user = $this->bookingUser();

        $this->actingAs($user)
            ->post(route('flights.bookings.store'), $this->payload())
            ->assertRedirect();

        $booking = Booking::firstOrFail();
        Queue::assertPushed(FulfilBookingJob::class, fn ($job): bool => $job->bookingId === $booking->id);

        $this->assertSame($user->id, $booking->user_id);
        $this->assertSame(BookingStatus::Processing, $booking->status);
        $this->assertSame('test', $booking->environment);
        $this->assertTrue($booking->is_lcc);
        $this->assertEqualsWithDelta(6400, (float) $booking->total_amount, 0.001);
        $this->assertCount(1, $booking->pax);
        $this->assertStringStartsWith('MT-', $booking->reference);
    }

    public function test_store_keeps_the_raw_fare_quote_response_verbatim(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->post(route('flights.bookings.store'), $this->payload())
            ->assertRedirect();

        $booking = Booking::firstOrFail();

        // Book echoes the priced itinerary back field-for-field, so the response is
        // stored whole — not the UI transform, which drops most of what Book wants.
        $this->assertSame($this->fixture('farequote.json'), $booking->quote_raw);

        // Two fields the transform provably loses, to catch a "raw" that is quietly
        // re-derived from the DTO rather than kept as it arrived.
        $result = $booking->quote_raw['Response']['Results'];
        $this->assertSame(6, $result['Source']);
        $this->assertSame('PHP', $result['FareBreakdown'][0]['Currency']);
        $this->assertArrayNotHasKey('Source', $booking->quote);
    }

    public function test_store_copies_the_contact_block_onto_every_passenger(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->post(route('flights.bookings.store'), $this->payload([
                'passengers' => [
                    ['type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz', 'gender' => 'M', 'dateOfBirth' => '1990-08-15'],
                    ['type' => 'Child', 'title' => 'Miss', 'firstName' => 'Ana', 'lastName' => 'Cruz', 'gender' => 'F', 'dateOfBirth' => '2018-03-04'],
                ],
            ]))
            ->assertRedirect();

        // TBO wants an address on each passenger, so the shared block is fanned out —
        // including onto the child, who obviously did not type one.
        foreach (Booking::firstOrFail()->pax as $row) {
            $this->assertSame('123 Rizal Street', $row['addressLine1']);
            $this->assertSame('Makati', $row['city']);
            $this->assertSame('63', $row['mobileCountryCode']);
            $this->assertSame('agent@example.com', $row['email']);
            $this->assertSame('PH', $row['countryCode']);
            // Derived from the code, never collected, so the two cannot disagree.
            $this->assertSame('Philippines', $row['countryName']);
        }
    }

    public function test_store_marks_exactly_one_adult_as_lead_passenger(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->post(route('flights.bookings.store'), $this->payload([
                'passengers' => [
                    // A child flagged as lead, which TBO will not accept...
                    ['type' => 'Child', 'title' => 'Miss', 'firstName' => 'Ana', 'lastName' => 'Cruz', 'gender' => 'F', 'isLeadPax' => true, 'dateOfBirth' => '2018-03-04'],
                    ['type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz', 'gender' => 'M', 'dateOfBirth' => '1990-08-15'],
                    ['type' => 'Adult', 'title' => 'Mrs', 'firstName' => 'Maria', 'lastName' => 'Cruz', 'gender' => 'F', 'dateOfBirth' => '1990-08-15'],
                ],
            ]))
            ->assertRedirect();

        $pax = Booking::firstOrFail()->pax;

        // ...so the flag moves to the first adult, and only that one carries it.
        $this->assertSame([false, true, false], array_column($pax, 'isLeadPax'));
    }

    public function test_store_rejects_a_title_tbo_cannot_encode(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->post(route('flights.bookings.store'), $this->payload([
                'passengers' => [
                    ['type' => 'Adult', 'title' => 'Dr', 'firstName' => 'Juan', 'lastName' => 'Cruz', 'gender' => 'M', 'dateOfBirth' => '1990-08-15'],
                ],
            ]))
            ->assertSessionHasErrors('passengers.0.title');
    }

    public function test_store_requires_the_address_tbo_asks_for(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->post(route('flights.bookings.store'), $this->payload([
                'contact' => ['email' => 'agent@example.com', 'phone' => '09170000000'],
            ]))
            ->assertSessionHasErrors(['contact.addressLine1', 'contact.city', 'contact.countryCode', 'contact.mobileCountryCode']);
    }

    /**
     * The combination that got past us on a real PR fare: not required at Book, but
     * required at Ticket. These are independent flags, not fallbacks — chaining them
     * with `??` stopped at the first `false` and collected no passport, and TBO then
     * refused the Book outright.
     */
    public function test_store_enforces_passport_when_only_the_ticket_flag_is_set(): void
    {
        $this->fakeQuote('farequote-passport-at-ticket.json');

        $this->actingAs($this->bookingUser())
            ->post(route('flights.bookings.store'), $this->payload()) // no passport details
            ->assertSessionHasErrors('booking');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_store_enforces_passport_when_the_fare_requires_it(): void
    {
        $this->fakeQuote('farequote-passport.json'); // IsPassportRequiredAtBook = true

        $this->actingAs($this->bookingUser())
            ->post(route('flights.bookings.store'), $this->payload()) // no passport details
            ->assertSessionHasErrors('booking');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_store_accepts_passengers_with_passport_when_required(): void
    {
        $this->fakeQuote('farequote-passport.json');

        $this->actingAs($this->bookingUser())
            ->post(route('flights.bookings.store'), $this->payload([
                'passengers' => [[
                    'type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz',
                    'documentNumber' => 'P1234567', 'documentExpiry' => '2030-01-01', 'nationality' => 'PH', 'dateOfBirth' => '1990-08-15']],
            ]))
            ->assertRedirect();

        $this->assertDatabaseCount('bookings', 1);
    }

    /**
     * A blank date of birth used to reach TBO as `DateOfBirth: null` and come back as
     * "Invalid Date of Birth of Adult" (Code 3) — at Ticket, after the wallet was
     * charged. Booking MT-7YIS7LRE died that way on DEL→DXB.
     */
    public function test_store_refuses_a_passenger_without_a_date_of_birth(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->postJson(route('flights.bookings.store'), $this->payload([
                'passengers' => [
                    ['type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Mike', 'lastName' => 'Alibo', 'gender' => 'M'],
                ],
            ]))
            ->assertStatus(422);

        $this->assertSame(0, Booking::count());
    }

    /**
     * TBO returns that same opaque message when the date of birth does not match the
     * passenger type, so the mismatch is caught here with words an agent can act on.
     */
    public function test_store_refuses_an_adult_who_is_too_young_for_the_fare(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->postJson(route('flights.bookings.store'), $this->payload([
                'passengers' => [
                    ['type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Ana', 'lastName' => 'Cruz',
                        'gender' => 'F', 'dateOfBirth' => '2020-01-01'],
                ],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'does not match the adult fare'));

        $this->assertSame(0, Booking::count());
    }

    /** And the bands themselves: a child on a child fare is fine. */
    public function test_store_accepts_a_child_whose_age_matches_the_fare(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->post(route('flights.bookings.store'), $this->payload([
                'passengers' => [
                    ['type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz',
                        'gender' => 'M', 'dateOfBirth' => '1990-08-15'],
                    ['type' => 'Child', 'title' => 'Miss', 'firstName' => 'Ana', 'lastName' => 'Cruz',
                        'gender' => 'F', 'dateOfBirth' => '2018-03-04'],
                ],
            ]))
            ->assertRedirect();

        $this->assertSame(1, Booking::count());
    }

    public function test_store_returns_a_json_redirect_for_xhr(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->postJson(route('flights.bookings.store'), $this->payload())
            ->assertOk()
            ->assertJsonStructure(['redirect', 'reference']);

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_store_json_returns_422_when_passport_missing(): void
    {
        $this->fakeQuote('farequote-passport.json');

        $this->actingAs($this->bookingUser())
            ->postJson(route('flights.bookings.store'), $this->payload()) // no passport
            ->assertStatus(422);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_store_folds_selected_ancillaries_into_the_total(): void
    {
        $this->fakeQuote(); // LCC fare + SSR options

        $this->actingAs($this->bookingUser())
            ->post(route('flights.bookings.store'), $this->payload([
                'passengers' => [[
                    'type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz',
                    'baggage' => 'PBAG20', 'meal' => 'HFML', 'dateOfBirth' => '1990-08-15']],
            ]))
            ->assertRedirect();

        $booking = Booking::firstOrFail();
        $this->assertEqualsWithDelta(1550, (float) $booking->ancillary_total, 0.001); // 1200 + 350
        $this->assertEqualsWithDelta(7950, (float) $booking->total_amount, 0.001);     // 6400 + 1550
        // Stored as a list now — add-ons are per leg — but a single code still works.
        $this->assertSame('PBAG20', data_get($booking->pax, '0.ssr.baggage.0.code'));
        $this->assertSame('HFML', data_get($booking->pax, '0.ssr.meal.0.code'));
    }

    /**
     * The whole point of per-leg add-ons: a return trip can buy a meal each way, and
     * both are priced and stored. A single code bought one leg and silently left the
     * other empty.
     */
    public function test_store_prices_an_add_on_on_every_leg_it_was_bought_for(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->post(route('flights.bookings.store'), $this->payload([
                'passengers' => [[
                    'type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz',
                    'dateOfBirth' => '1990-08-15',
                    // Both legs the SSR fixture offers, keyed by code|origin|destination.
                    'baggage' => ['PBAG20|MNL|CEB', 'PBAG32|MNL|CEB'],
                    'meal' => [],
                ]],
            ]))
            ->assertRedirect();

        $booking = Booking::firstOrFail();
        $this->assertCount(2, data_get($booking->pax, '0.ssr.baggage'));
        $this->assertEqualsWithDelta(3200, (float) $booking->ancillary_total, 0.001); // 1200 + 2000
    }

    public function test_store_rejects_extra_baggage_for_an_infant(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->post(route('flights.bookings.store'), $this->payload([
                // An accompanying adult, so this exercises the infant baggage guard
                // rather than the "needs an adult" one. TBO's title enum has no
                // Mstr — an infant boy is Mr, its only male value.
                'passengers' => [
                    ['type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz', 'gender' => 'M', 'dateOfBirth' => '1990-08-15'],
                    ['type' => 'Infant', 'title' => 'Mr', 'firstName' => 'Baby', 'lastName' => 'Cruz', 'baggage' => 'PBAG20', 'dateOfBirth' => '2025-06-01'],
                ],
            ]))
            ->assertSessionHasErrors('booking');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_store_rejects_a_booking_with_no_adult(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->bookingUser())
            ->post(route('flights.bookings.store'), $this->payload([
                'passengers' => [
                    ['type' => 'Child', 'title' => 'Miss', 'firstName' => 'Ana', 'lastName' => 'Cruz', 'gender' => 'F', 'dateOfBirth' => '2018-03-04'],
                ],
            ]))
            ->assertSessionHasErrors('booking');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_store_validates_the_input(): void
    {
        $this->actingAs($this->bookingUser())
            ->postJson(route('flights.bookings.store'), ['passengers' => []])
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

    public function test_prices_are_displayed_to_the_centavo(): void
    {
        // The wallet is debited to the centavo, so a rounded display would show a
        // figure nobody is actually charged. 6400.75 must not render as 6,401.
        $user = $this->bookingUser();
        $booking = Booking::factory()->create([
            'user_id' => $user->id,
            'reference' => 'MT-CENTAVO1',
            'total_amount' => '6400.75',
            'ancillary_total' => '250.25',
        ]);

        $this->actingAs($user)
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee('6,400.75')
            ->assertDontSee('6,401');

        $this->actingAs($user)
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('6,400.75')
            ->assertSee('250.25');
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
