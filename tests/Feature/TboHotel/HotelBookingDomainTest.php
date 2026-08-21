<?php

namespace Tests\Feature\TboHotel;

use App\Enums\BookingProduct;
use App\Enums\BookingStatus;
use App\Enums\Supplier;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\Pricing\PricingContextFactory;
use App\Services\Pricing\PricingEngine;
use App\Services\TboHotel\DTO\Guest;
use App\Services\TboHotel\DTO\PaxRoom;
use App\Services\TboHotel\DTO\SearchInput;
use App\Services\TboHotel\Exceptions\TboHotelException;
use App\Services\TboHotel\HotelBookingService;
use App\Services\TboHotel\TboHotelClient;
use App\Services\TboHotel\TboHotelConfig;
use App\Services\TboHotel\TboHotelService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HotelBookingDomainTest extends TestCase
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

        Hotel::create([
            'source' => 'tbo', 'code' => '1012705', 'city_code' => '127116',
            'country_code' => 'PH', 'name' => 'Jen s Comfy Home', 'rating' => 3,
            'address' => '123 Somewhere St',
        ]);
    }

    private function service(): HotelBookingService
    {
        return new HotelBookingService(
            new TboHotelService(new TboHotelClient(TboHotelConfig::for('test'))),
            app(WalletService::class),
            app(PricingEngine::class),
            app(PricingContextFactory::class),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tbohotel/{$name}.json")), true);
    }

    private function fakePreBook(string $fixture = 'prebook'): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response($this->fixture($fixture))]);
    }

    private function booker(string $balance = '100000.00'): User
    {
        $agency = Agency::factory()->create();
        Wallet::create(['agency_id' => $agency->id, 'currency' => 'PHP', 'balance' => $balance]);

        return User::factory()->create(['agency_id' => $agency->id]);
    }

    private function search(int $rooms = 1): SearchInput
    {
        return new SearchInput(
            checkIn: '2026-09-11',
            checkOut: '2026-09-13',
            rooms: $rooms === 1
                ? [new PaxRoom(2, 0, [])]
                : [new PaxRoom(2, 0, []), new PaxRoom(2, 1, [8])],
            guestNationality: 'PH',
            locationType: 'hotel',
            locationCode: '1012705',
        );
    }

    /**
     * @return array<int, Guest>
     */
    private function guests(int $rooms = 1): array
    {
        $guests = [
            new Guest('Mr', 'Juan', 'Dela Cruz', Guest::ADULT, 0, true),
            new Guest('Mrs', 'Ana', 'Dela Cruz', Guest::ADULT, 0),
        ];

        if ($rooms === 2) {
            $guests[] = new Guest('Mr', 'Jose', 'Rizal', Guest::ADULT, 1);
            $guests[] = new Guest('Ms', 'Maria', 'Rizal', Guest::ADULT, 1);
            $guests[] = new Guest('Mr', 'Pepe', 'Rizal', Guest::CHILD, 1);
        }

        return $guests;
    }

    /**
     * @return array{email: string, phone: string}
     */
    private function contact(): array
    {
        return ['email' => 'agent@example.test', 'phone' => '+639171234567'];
    }

    public function test_it_persists_a_quoted_booking_with_its_hotel_detail(): void
    {
        $this->fakePreBook();
        $user = $this->booker();

        $booking = $this->service()->createFromQuote($user, $this->search(), self::CODE, $this->guests(), $this->contact());

        $this->assertSame(BookingStatus::Quoted, $booking->status);
        $this->assertSame(BookingProduct::Hotel, $booking->product);
        $this->assertSame(Supplier::TboHotel, $booking->supplier);
        $this->assertSame('test', $booking->environment);
        $this->assertStringStartsWith('MT-', $booking->reference);

        $detail = $booking->hotel;
        $this->assertSame('1012705', $detail->hotel_code);
        $this->assertSame('Jen s Comfy Home', $detail->hotel_name);
        $this->assertSame(2, $detail->nights);
        $this->assertSame(1, $detail->rooms_count);
        $this->assertSame('PH', $detail->guest_nationality);
        $this->assertTrue($detail->is_refundable);
    }

    /**
     * §18 makes PreBook's price final, so that — not whatever the browser posted — is
     * what the agency is charged.
     */
    public function test_the_charge_is_prebooks_price_not_the_clients(): void
    {
        $this->fakePreBook();
        $user = $this->booker();

        $booking = $this->service()->createFromQuote(
            $user, $this->search(), self::CODE, $this->guests(), $this->contact(),
            shownSellFare: 4036.02,
        );

        $this->assertSame('4036.02', $booking->total_amount);
        $this->assertSame('95963.98', (string) $user->agency->wallet->fresh()->balance);
    }

    /**
     * A price move is normal and must be shown, not swallowed. Booking silently at the
     * new figure spends money on a number the agency never saw.
     */
    public function test_a_price_move_stops_the_booking_until_it_is_accepted(): void
    {
        $this->fakePreBook();
        $user = $this->booker();

        try {
            $this->service()->createFromQuote(
                $user, $this->search(), self::CODE, $this->guests(), $this->contact(),
                shownSellFare: 3500.00,
            );
            $this->fail('A re-price should have stopped the booking.');
        } catch (BookingException $e) {
            $this->assertStringContainsString('3,500.00', $e->getMessage());
            $this->assertStringContainsString('4,036.02', $e->getMessage());
        }

        $this->assertDatabaseCount('bookings', 0);
        $this->assertSame('100000.00', (string) $user->agency->wallet->fresh()->balance);
    }

    public function test_an_accepted_price_move_goes_through(): void
    {
        $this->fakePreBook();
        $user = $this->booker();

        $booking = $this->service()->createFromQuote(
            $user, $this->search(), self::CODE, $this->guests(), $this->contact(),
            shownSellFare: 3500.00, acceptPriceChange: true,
        );

        $this->assertSame('4036.02', $booking->total_amount);
    }

    /**
     * PreBook's terms replace the search-time set — they are the ones a refund will be
     * computed from.
     */
    public function test_it_stores_prebooks_policies_conditions_and_amenities(): void
    {
        $this->fakePreBook();

        $detail = $this->service()
            ->createFromQuote($this->booker(), $this->search(), self::CODE, $this->guests(), $this->contact())
            ->hotel;

        $this->assertNotEmpty($detail->cancel_policies);
        $this->assertNotEmpty($detail->rate_conditions);
        $this->assertNotEmpty($detail->amenities);
        $this->assertStringContainsString('cancellation charge', $detail->rate_conditions[0]);
    }

    /**
     * The codes run past 100 characters and are segmented; a truncating column makes
     * the booking unbookable and nothing notices until Book fails.
     */
    public function test_the_booking_code_survives_intact(): void
    {
        $this->fakePreBook();

        $detail = $this->service()
            ->createFromQuote($this->booker(), $this->search(), self::CODE, $this->guests(), $this->contact())
            ->hotel;

        $this->assertSame(self::CODE, $detail->booking_code);
    }

    public function test_a_two_room_stay_records_both_rooms(): void
    {
        $this->fakePreBook('prebook-multiroom');

        $booking = $this->service()->createFromQuote(
            $this->booker(), $this->search(2), self::CODE, $this->guests(2), $this->contact(),
        );

        $this->assertSame(2, $booking->hotel->rooms_count);
        $this->assertCount(2, $booking->hotel->room_names);
        $this->assertSame('40203.88', $booking->total_amount);
    }

    /**
     * TBO prices per room and per occupant, so names that do not match the searched
     * occupancy describe a different booking from the one that was quoted.
     */
    public function test_guests_must_match_the_occupancy_that_was_priced(): void
    {
        $this->fakePreBook();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Room 1 was priced for 2 adult(s) and 0 child(ren) — 1 and 0 were given.');

        $this->service()->createFromQuote(
            $this->booker(), $this->search(), self::CODE,
            [new Guest('Mr', 'Juan', 'Dela Cruz', Guest::ADULT, 0, true)],
            $this->contact(),
        );
    }

    public function test_a_guest_in_a_room_that_is_not_booked_is_refused(): void
    {
        $this->fakePreBook();

        $this->expectException(BookingException::class);

        $this->service()->createFromQuote(
            $this->booker(), $this->search(), self::CODE,
            [...$this->guests(), new Guest('Mr', 'Extra', 'Person', Guest::ADULT, 5)],
            $this->contact(),
        );
    }

    public function test_a_nameless_guest_is_refused(): void
    {
        $this->fakePreBook();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Every guest needs a first and last name.');

        $this->service()->createFromQuote(
            $this->booker(), $this->search(), self::CODE,
            [new Guest('Mr', 'Juan', 'Dela Cruz', Guest::ADULT, 0, true), new Guest('Mrs', '', '', Guest::ADULT, 0)],
            $this->contact(),
        );
    }

    /**
     * Exactly one lead, an adult, in the first room — that is who the desk asks for.
     */
    public function test_exactly_one_adult_leads_the_booking(): void
    {
        $this->fakePreBook();

        $booking = $this->service()->createFromQuote(
            $this->booker(), $this->search(), self::CODE,
            // Nobody flagged: the first adult of room one should be adopted.
            [new Guest('Mr', 'Juan', 'Dela Cruz'), new Guest('Mrs', 'Ana', 'Dela Cruz')],
            $this->contact(),
        );

        $leads = array_filter($booking->pax, fn (array $g): bool => $g['isLead']);

        $this->assertCount(1, $leads);
        $this->assertSame('Juan', reset($leads)['firstName']);
    }

    /**
     * A flag on a child is not honoured: the lead is who the hotel asks for at the
     * desk, and a nine-year-old cannot hold that role.
     */
    public function test_a_child_cannot_lead_the_booking(): void
    {
        $this->fakePreBook();

        $family = new SearchInput(
            checkIn: '2026-09-11',
            checkOut: '2026-09-13',
            rooms: [new PaxRoom(2, 1, [9])],
            guestNationality: 'PH',
            locationType: 'hotel',
            locationCode: '1012705',
        );

        $booking = $this->service()->createFromQuote(
            $this->booker(), $family, self::CODE,
            [
                new Guest('Mr', 'Junior', 'Dela Cruz', Guest::CHILD, 0, true),
                new Guest('Mr', 'Juan', 'Dela Cruz', Guest::ADULT, 0),
                new Guest('Mrs', 'Ana', 'Dela Cruz', Guest::ADULT, 0),
            ],
            $this->contact(),
        );

        $leads = array_values(array_filter($booking->pax, fn (array $g): bool => $g['isLead']));

        $this->assertCount(1, $leads);
        $this->assertSame('Adult', $leads[0]['type']);
        $this->assertSame('Juan', $leads[0]['firstName']);
    }

    /**
     * A booking nobody paid for is worse than no booking: the whole thing rolls back.
     */
    public function test_a_short_wallet_rolls_the_booking_back(): void
    {
        $this->fakePreBook();
        $user = $this->booker('100.00');

        try {
            $this->service()->createFromQuote($user, $this->search(), self::CODE, $this->guests(), $this->contact());
            $this->fail('A short balance should have stopped the booking.');
        } catch (BookingException) {
            // expected
        }

        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('hotel_bookings', 0);
        $this->assertSame('100.00', (string) $user->agency->wallet->fresh()->balance);
    }

    /**
     * An expired BookingCode must never reach the wallet.
     */
    public function test_an_expired_rate_creates_nothing(): void
    {
        Http::fake([self::BASE.'/PreBook' => Http::response(['Status' => ['Code' => 315, 'Description' => 'Session Expired']])]);

        $this->expectException(TboHotelException::class);

        try {
            $this->service()->createFromQuote($this->booker(), $this->search(), self::CODE, $this->guests(), $this->contact());
        } finally {
            $this->assertDatabaseCount('bookings', 0);
        }
    }

    /**
     * Platform staff have no agency and so no wallet — the booking still stands.
     */
    public function test_a_booker_without_an_agency_is_charged_nothing(): void
    {
        $this->fakePreBook();
        $user = User::factory()->create(['agency_id' => null]);

        $booking = $this->service()->createFromQuote($user, $this->search(), self::CODE, $this->guests(), $this->contact());

        $this->assertSame(BookingStatus::Quoted, $booking->status);
        $this->assertNull($booking->walletCharge());
    }

    public function test_the_raw_prebook_envelope_is_kept_for_the_audit_trail(): void
    {
        $this->fakePreBook();

        $booking = $this->service()->createFromQuote($this->booker(), $this->search(), self::CODE, $this->guests(), $this->contact());

        $this->assertArrayHasKey('Status', $booking->quote_raw);
        $this->assertArrayNotHasKey('raw', $booking->quote);
    }

    public function test_the_booking_reference_is_unique_across_products(): void
    {
        $this->fakePreBook();
        $user = $this->booker();

        $a = $this->service()->createFromQuote($user, $this->search(), self::CODE, $this->guests(), $this->contact());
        $b = $this->service()->createFromQuote($user, $this->search(), self::CODE, $this->guests(), $this->contact());

        $this->assertNotSame($a->reference, $b->reference);
        $this->assertSame(2, Booking::where('product', BookingProduct::Hotel)->count());
    }
}
