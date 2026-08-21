<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\BookingPriceLayer;
use App\Models\User;
use App\Services\Booking\ETicket;
use App\Services\TboAir\DTO\FareQuote;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The printable e-ticket.
 *
 * This is the one page that leaves the building — an agency prints it and hands it to a
 * traveller who takes it to a check-in desk. So the things pinned here are mostly about
 * it telling the truth: a held PNR must not print the word "e-Ticket", a test-environment
 * booking must say so, and the passenger copy must genuinely omit the fare rather than
 * merely hiding it visually.
 */
class BookingETicketTest extends TestCase
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

    private function booking(User $user, array $overrides = []): Booking
    {
        $quote = FareQuote::fromResponse($this->fixture('farequote.json'))->toArray();

        return Booking::factory()->create(array_merge([
            'user_id' => $user->id,
            'agency_id' => $user->agency_id,
            'environment' => 'test',
            'status' => BookingStatus::Ticketed,
            'is_lcc' => false,
            'pnr' => '984XIX',
            'booking_id' => '75133',
            'currency' => 'PHP',
            'total_amount' => '14858.75',
            'ancillary_total' => '0',
            'quote' => $quote,
            'quote_raw' => $this->fixture('farequote.json'),
            'contact' => [
                'email' => 'agent@example.com', 'phone' => '9171234567', 'mobileCountryCode' => '63',
                'addressLine1' => '123 Rizal Street', 'city' => 'Makati', 'countryCode' => 'PH',
            ],
            'pax' => [[
                'type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Dela Cruz',
                'gender' => 'M', 'dateOfBirth' => '1990-08-15', 'isLeadPax' => true,
                'nationality' => 'PH',
                'documentNumber' => 'UMID-1234-5678901', 'documentExpiry' => '2032-08-18',
                'ticketNumber' => '5014484654', 'ticketIssuedAt' => '2026-08-10T08:54:40',
            ]],
        ], $overrides));
    }

    public function test_it_prints_the_whole_journey(): void
    {
        $user = $this->userWith(['booking.view']);

        $this->actingAs($user)
            ->get(route('bookings.eticket', $this->booking($user)))
            ->assertOk()
            ->assertSee('Manila (MNL) to Cebu (CEB)')
            ->assertSee('Cebu Pacific')
            ->assertSee('5J561')
            ->assertSee('Terminal 3')                  // the gate the passenger has to find
            ->assertSee('Ninoy Aquino', false)
            ->assertSee('984XIX')                      // airline PNR
            ->assertSee('75133');                      // airline booking id
    }

    public function test_it_prints_the_passenger_and_their_ticket(): void
    {
        $user = $this->userWith(['booking.view']);

        $this->actingAs($user)
            ->get(route('bookings.eticket', $this->booking($user)))
            ->assertOk()
            ->assertSee('e-Ticket')
            ->assertSee('JUAN DELA CRUZ')              // as it must read at check-in
            ->assertSee('5014484654')
            ->assertSee('UMID-1234-5678901')
            ->assertSee('Expires 2032-08-18');
    }

    /**
     * The heading follows the ticket numbers, not the status.
     *
     * The live system prints "e-Ticket" over a held PNR, which hands the traveller a
     * document claiming a ticket nobody paid for.
     */
    public function test_a_held_booking_does_not_call_itself_an_eticket(): void
    {
        $user = $this->userWith(['booking.view']);

        $booking = $this->booking($user, [
            'status' => BookingStatus::Booked,
            'pax' => [[
                'type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Dela Cruz',
                'isLeadPax' => true, 'documentNumber' => 'UMID-1234-5678901', 'dateOfBirth' => '1990-08-15']],
        ]);

        $this->actingAs($user)
            ->get(route('bookings.eticket', $booking))
            ->assertOk()
            ->assertSee('Booking Confirmation')
            ->assertSee('This is a reservation, not a ticket.')
            ->assertSee('Not issued')
            ->assertDontSee('>e-Ticket<', false);      // not as the heading
    }

    /** A test-environment printout must never pass for a travel document. */
    public function test_a_test_booking_says_it_is_not_valid_for_travel(): void
    {
        $user = $this->userWith(['booking.view']);

        $this->actingAs($user)
            ->get(route('bookings.eticket', $this->booking($user)))
            ->assertOk()
            ->assertSee('NOT VALID FOR TRAVEL');
    }

    /** The copy handed to the traveller omits what the agency paid. */
    public function test_the_passenger_copy_omits_the_fare(): void
    {
        $user = $this->userWith(['booking.view']);

        $booking = $this->booking($user);
        $quote = $booking->quote;
        $quote['fareBreakdown'] = [[
            'passengerType' => 'Adult', 'count' => 2, 'baseFare' => 11852.16, 'tax' => 3006.59,
        ]];
        $booking->update(['quote' => $quote]);

        // With fares: the real two-adult figures, not doubled.
        $this->actingAs($user)
            ->get(route('bookings.eticket', $booking))
            ->assertOk()
            ->assertSee('14,858.75')
            ->assertSee('5,926.08')                    // base fare per head
            ->assertDontSee('29,717.50');              // what multiplying by count again would give

        // Passenger copy: gone entirely, not merely hidden.
        $this->actingAs($user)
            ->get(route('bookings.eticket', [$booking, 'prices' => 0]))
            ->assertOk()
            ->assertSee('JUAN DELA CRUZ')
            ->assertDontSee('14,858.75')
            ->assertDontSee('11,852.16');
    }

    /**
     * The printed fare must add up, and must be the SELLING fare.
     *
     * The stored quote's per-passenger rows are the supplier's own and sum to the net —
     * on PNR 984XIX, ten centavos below what we charged, and once markup is switched on,
     * the whole markup below it. Allocating them to the selling total does both jobs at
     * once: the document reconciles with itself, and the supplier's figures never reach
     * the page.
     */
    public function test_the_fare_lines_are_allocated_to_the_selling_total(): void
    {
        $user = $this->userWith(['booking.view']);

        $booking = $this->booking($user, ['total_amount' => '14858.85']);
        $quote = $booking->quote;
        $quote['fareBreakdown'] = [[
            'passengerType' => 'Adult', 'count' => 2, 'baseFare' => 11852.16, 'tax' => 3006.59,
        ]];
        $booking->update(['quote' => $quote]);

        $ticket = ETicket::for($booking->fresh());

        // Nothing left over: the lines were allocated to the total rather than copied
        // from the supplier, so there is no gap to reconcile.
        $this->assertSame(0.0, $ticket->otherCharges());

        // This viewer is platform staff, who may see the supplier's own components.
        $this->actingAs($user)
            ->get(route('bookings.eticket', $booking))
            ->assertOk()
            ->assertSee('14,858.85')
            ->assertDontSee('Other charges');
    }

    /**
     * The same document, printed by an agency.
     *
     * The supplier's base fare and tax sum to the net, so on an agency's copy they are
     * replaced by the selling fare. Otherwise the printed ticket gives up our cost — and
     * once markup is on, the Main Office's margin as the gap to the total beneath it.
     */
    public function test_an_agency_copy_carries_no_supplier_figures(): void
    {
        $agency = Agency::factory()->create();
        $user = $this->agencyUserWith($agency, ['booking.view']);

        $booking = $this->booking($user, ['total_amount' => '16000.00', 'agency_id' => $agency->id]);
        $quote = $booking->quote;
        $quote['fareBreakdown'] = [[
            'passengerType' => 'Adult', 'count' => 2, 'baseFare' => 11852.16, 'tax' => 3006.59,
        ]];
        $booking->update(['quote' => $quote]);

        $this->actingAs($user)
            ->get(route('bookings.eticket', $booking))
            ->assertOk()
            ->assertSee('16,000.00')       // what the agency was charged
            ->assertDontSee('11,852.16')   // the supplier's base fare
            ->assertDontSee('3,006.59')    // and its tax
            ->assertDontSee('14,858.75')   // which together are the net
            ->assertDontSee('Other charges');
    }

    /** No reconciling line when the lines already add up. */
    public function test_it_prints_no_other_charges_line_when_the_fare_is_exact(): void
    {
        $user = $this->userWith(['booking.view']);

        $booking = $this->booking($user, ['total_amount' => '14858.75']);
        $quote = $booking->quote;
        $quote['fareBreakdown'] = [[
            'passengerType' => 'Adult', 'count' => 2, 'baseFare' => 11852.16, 'tax' => 3006.59,
        ]];
        $booking->update(['quote' => $quote]);

        $this->actingAs($user)
            ->get(route('bookings.eticket', $booking))
            ->assertOk()
            ->assertDontSee('Other charges');
    }

    public function test_it_names_the_operating_carrier_when_it_differs(): void
    {
        $user = $this->userWith(['booking.view']);

        // PNR 984XIX really was sold by PR and flown by 2P — the desk the passenger
        // has to find is the operating carrier's.
        $raw = $this->fixture('farequote.json');
        $raw['Response']['Results']['Segments'][0][0]['Airline']['OperatingCarrier'] = '2P';

        $this->actingAs($user)
            ->get(route('bookings.eticket', $this->booking($user, ['quote_raw' => $raw])))
            ->assertOk()
            ->assertSee('Operated by 2P');
    }

    /**
     * The agency's own branding, so the traveller knows who to call.
     *
     * They booked with the agency, not with us and not with the airline — when a flight
     * moves, the agency is who they ring.
     */
    public function test_it_carries_the_agencys_branding_and_contact_details(): void
    {
        $agency = Agency::factory()->create([
            'name' => 'Mosconi Tours',
            'logo_path' => 'agency-logos/mosconi.png',
            'contact_email' => 'support@mosconitours.test',
            'contact_phone' => '+63 2 8888 1234',
        ]);
        $user = $this->agencyUserWith($agency, ['booking.view']);

        $this->actingAs($user)
            ->get(route('bookings.eticket', $this->booking($user)))
            ->assertOk()
            ->assertSee('Mosconi Tours')
            ->assertSee('agency-logos/mosconi.png')       // their logo, not ours
            ->assertSee('support@mosconitours.test')
            ->assertSee('+63 2 8888 1234')
            ->assertSee('Need help with this booking?');
    }

    /**
     * An agency that has not uploaded a logo or filled in a contact still gets a usable
     * document: our mark stands in, and the agent who made the booking is the contact.
     */
    public function test_it_falls_back_to_our_logo_and_the_booking_agent(): void
    {
        $agency = Agency::factory()->create([
            'name' => 'Sunrise Travel',
            'logo_path' => null,
            'contact_email' => null,
            'contact_phone' => null,
        ]);
        $user = $this->agencyUserWith($agency, ['booking.view']);

        $this->actingAs($user)
            ->get(route('bookings.eticket', $this->booking($user)))
            ->assertOk()
            ->assertSee('Sunrise Travel')          // still their name
            ->assertSee('favicon.png')             // our mark stands in
            ->assertSee($user->email);             // the agent who booked it
    }

    /** A quote has no reservation behind it, so there is nothing to print. */
    public function test_a_booking_without_a_pnr_cannot_be_printed(): void
    {
        $user = $this->userWith(['booking.view']);

        $booking = $this->booking($user, ['pnr' => null, 'status' => BookingStatus::Quoted]);

        $this->actingAs($user)->get(route('bookings.eticket', $booking))->assertNotFound();
    }

    /**
     * A booking with bags and meals on it, priced the way BookingService prices one:
     * ancillaries folded into net, the sum marked up once.
     *
     * Net 10,000 fare + 1,000 of add-ons, a 10% office rung and a flat ₱250 agency fee
     * → 11,000 × 1.10 + 250 = 12,350.
     */
    private function bookingWithAddOns(User $user): Booking
    {
        $booking = $this->booking($user, [
            'is_lcc' => true,
            'total_amount' => '12350.00',
            'net_amount' => '11000.00',
            'markup_total' => '1350.00',
            'ancillary_total' => '1000.00',
        ]);

        $pax = $booking->pax;
        $pax[0]['ssr'] = [
            'baggage' => [['code' => 'B5', 'label' => '5 kg', 'price' => 800.0, 'origin' => 'MNL', 'destination' => 'CEB']],
            'meal' => [['code' => 'M1', 'label' => 'Cake', 'price' => 200.0, 'origin' => 'MNL', 'destination' => 'CEB']],
        ];
        $booking->forceFill(['pax' => $pax])->save();

        $office = Agency::factory()->create(['name' => 'Main Office']);

        $booking->priceLayers()->create([
            'level' => BookingPriceLayer::MAIN_OFFICE, 'agency_id' => $office->id, 'basis_amount' => '11000.00',
            'markup_amount' => '1100.00', 'running_total' => '12100.00', 'created_at' => now(),
            'rule_snapshot' => ['calc_type' => 'percentage_markup', 'applies_to' => 'total', 'value' => '10.0000'],
        ]);
        $booking->priceLayers()->create([
            'level' => BookingPriceLayer::AGENCY, 'agency_id' => $booking->agency_id ?? $office->id, 'basis_amount' => '11000.00',
            'markup_amount' => '250.00', 'running_total' => '12350.00', 'created_at' => now(),
            'rule_snapshot' => ['calc_type' => 'fixed', 'applies_to' => 'total', 'value' => '250.0000'],
        ]);

        return $booking->fresh();
    }

    /**
     * The add-ons were marked up when the booking was priced, so the document has to
     * say what they SOLD for. It printed `ancillary_total` — our cost — which put the
     * supplier's price for a bag in front of the agency and the traveller both.
     */
    public function test_the_add_on_lines_are_the_selling_price_not_the_supplier_price(): void
    {
        $user = $this->userWith(['booking.view']);
        $booking = $this->bookingWithAddOns($user);

        // 1,000 of cost carries the 10% rung — the flat ₱250 is charged once for the
        // booking, not once per bag — so the add-ons sold for 1,100.
        $this->assertSame('1100.00', (string) $booking->addOnSellTotal());

        $rows = ETicket::for($booking, withPrices: true, viewer: $user)->addOns();

        $this->assertSame(880.0, $rows[0]['price'], 'the 800 bag');
        $this->assertSame(220.0, $rows[1]['price'], 'the 200 meal');
        $this->assertSame(1100.0, array_sum(array_column($rows, 'price')), 'and they sum to the add-ons line');
    }

    /**
     * The fare rows were absorbing the add-on markup: the document subtracted the
     * ancillaries at COST from a selling total, so every passenger's fare came out
     * overstated by a share of someone else's baggage.
     */
    public function test_the_fare_rows_do_not_absorb_the_add_on_markup(): void
    {
        $user = $this->userWith(['booking.view']);
        $booking = $this->bookingWithAddOns($user);
        $ticket = ETicket::for($booking, withPrices: true, viewer: $user);

        $fare = array_sum(array_column($ticket->fareLines(), 'total'));

        // 12,350 total less the 1,100 the add-ons sold for.
        $this->assertSame(11250.0, round($fare, 2));

        // And the document still adds up to its own total, which is the point.
        $this->assertSame(
            12350.0,
            round($fare + $ticket->addOnTotal() + $ticket->otherCharges(), 2),
        );
    }

    public function test_the_printed_document_never_shows_the_supplier_price_of_an_add_on(): void
    {
        $user = $this->userWith(['booking.view']);
        $booking = $this->bookingWithAddOns($user);

        $this->actingAs($user)
            ->get(route('bookings.eticket', $booking))
            ->assertOk()
            ->assertSee('880.00')        // what the bag sold for
            ->assertSee('220.00')        // and the meal
            ->assertSee('1,100.00');     // and the add-ons line they sum to

        // Absence is asserted over the row data, not the HTML: "200.00" is also a
        // substring of the "5,200.00" sitting in the fare summary, and an assertion
        // that can pass or fail on a coincidence proves nothing either way.
        $supplierPrices = array_column(
            ETicket::for($booking, withPrices: true, viewer: $user)->addOns(),
            'price'
        );

        $this->assertNotContains(800.0, $supplierPrices, 'the supplier price of the bag');
        $this->assertNotContains(200.0, $supplierPrices, 'the supplier price of the meal');
    }

    public function test_it_is_not_readable_by_another_user(): void
    {
        $owner = $this->userWith(['booking.view']);
        $stranger = $this->userWith(['booking.view']);

        $this->actingAs($stranger)
            ->get(route('bookings.eticket', $this->booking($owner)))
            ->assertForbidden();
    }
}
