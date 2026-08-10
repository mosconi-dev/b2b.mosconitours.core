<?php

namespace Tests\Feature\Wallet;

use App\Enums\BookingStatus;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Booking\BookingService;
use App\Services\Wallet\WalletService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Booking spends the agency wallet. The fixture fare is 6,400.
 */
class BookingWalletChargeTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    private const FARE = '6400.00';

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->agency = Agency::factory()->create(['name' => 'Acme Travel']);
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/tboair/{$name}")), true);
    }

    private function fakeQuote(): void
    {
        Http::fake([
            '*Authenticate*' => Http::response($this->fixture('authenticate.json'), 200),
            '*FareQuote*' => Http::response($this->fixture('farequote.json'), 200),
            '*SSR*' => Http::response($this->fixture('ssr.json'), 200),
        ]);
    }

    /** An agent inside the agency, so their bookings draw on its wallet. */
    private function agent(): User
    {
        return $this->agencyUserWith($this->agency, [
            'flight.view', 'flight.search', 'booking.view', 'booking.create',
        ]);
    }

    private function fund(string $amount): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->for($this->agency), $amount, null, null, 'Opening balance');
    }

    private function balance(): string
    {
        return (string) app(WalletService::class)->for($this->agency)->fresh()->balance;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'traceId' => 'trace-abc-123',
            'resultIndex' => str_repeat('R', 400),
            'contact' => [
                'email' => 'agent@example.com', 'phone' => '09170000000', 'mobileCountryCode' => '63',
                'addressLine1' => '123 Rizal Street', 'city' => 'Makati', 'countryCode' => 'PH',
            ],
            'passengers' => [
                ['type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz', 'gender' => 'M'],
            ],
        ], $overrides);
    }

    // ---- Charging --------------------------------------------------------

    public function test_a_booking_debits_the_agency_wallet(): void
    {
        $this->fakeQuote();
        $this->fund('10000.00');

        $this->actingAs($this->agent())
            ->post(route('bookings.store'), $this->payload())
            ->assertRedirect();

        $this->assertSame('3600.00', $this->balance());

        $booking = Booking::firstOrFail();
        $charge = $booking->walletCharge();
        $this->assertNotNull($charge);
        $this->assertSame(self::FARE, (string) $charge->amount);
        $this->assertSame("Booking {$booking->reference}", $charge->description);
    }

    public function test_the_charge_is_attributed_to_the_agency_and_the_booker(): void
    {
        $this->fakeQuote();
        $this->fund('10000.00');
        $agent = $this->agent();

        $this->actingAs($agent)->post(route('bookings.store'), $this->payload())->assertRedirect();

        $charge = Booking::firstOrFail()->walletCharge();
        $this->assertSame($this->agency->id, $charge->agency_id);
        $this->assertSame($agent->id, $charge->user_id);
    }

    public function test_an_insufficient_balance_blocks_the_booking_entirely(): void
    {
        $this->fakeQuote();
        $this->fund('100.00');

        $this->actingAs($this->agent())
            ->post(route('bookings.store'), $this->payload())
            ->assertSessionHasErrors('booking');

        // The whole thing rolls back: no booking, no ledger entry, balance untouched.
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, WalletTransaction::where('direction', WalletTransaction::DEBIT)->count());
        $this->assertSame('100.00', $this->balance());
    }

    public function test_an_empty_wallet_blocks_the_booking(): void
    {
        $this->fakeQuote();

        $this->actingAs($this->agent())
            ->post(route('bookings.store'), $this->payload())
            ->assertSessionHasErrors('booking');

        $this->assertSame(0, Booking::count());
    }

    public function test_the_xhr_wizard_gets_a_422_with_the_shortfall(): void
    {
        $this->fakeQuote();
        $this->fund('100.00');

        $this->actingAs($this->agent())
            ->postJson(route('bookings.store'), $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'Insufficient wallet balance'));
    }

    public function test_exactly_enough_balance_is_allowed(): void
    {
        $this->fakeQuote();
        $this->fund(self::FARE);

        $this->actingAs($this->agent())
            ->post(route('bookings.store'), $this->payload())
            ->assertRedirect();

        $this->assertSame('0.00', $this->balance());
    }

    public function test_platform_staff_bookings_are_not_charged(): void
    {
        // No agency means no wallet — they are the operator, not a customer of it.
        $this->fakeQuote();
        $staff = $this->userWith(['flight.view', 'flight.search', 'booking.view', 'booking.create']);

        $this->actingAs($staff)->post(route('bookings.store'), $this->payload())->assertRedirect();

        $this->assertSame(1, Booking::count());
        $this->assertNull(Booking::firstOrFail()->walletCharge());
    }

    public function test_the_wallet_ledger_shows_the_booking(): void
    {
        $this->fakeQuote();
        $this->fund('10000.00');
        $agent = $this->agencyUserWith($this->agency, [
            'flight.view', 'flight.search', 'booking.view', 'booking.create', 'wallet.view',
        ]);

        $this->actingAs($agent)->post(route('bookings.store'), $this->payload())->assertRedirect();

        $this->actingAs($agent)
            ->get(route('wallet.index'))
            ->assertOk()
            ->assertSee('3,600.00')
            ->assertSee(Booking::firstOrFail()->reference);
    }

    // ---- Refunding -------------------------------------------------------

    private function bookedBooking(): Booking
    {
        $this->fakeQuote();
        $this->fund('10000.00');
        $this->actingAs($this->agent())->post(route('bookings.store'), $this->payload())->assertRedirect();

        return Booking::firstOrFail();
    }

    public function test_cancelling_gives_the_charge_back(): void
    {
        $booking = $this->bookedBooking();
        $this->assertSame('3600.00', $this->balance());

        app(BookingService::class)->transitionTo($booking, BookingStatus::Cancelled);

        $this->assertSame('10000.00', $this->balance());
        $this->assertTrue($booking->fresh()->wasRefundedToWallet());
    }

    public function test_a_failed_booking_gives_the_charge_back(): void
    {
        $booking = $this->bookedBooking();

        app(BookingService::class)->transitionTo($booking, BookingStatus::Failed);

        $this->assertSame('10000.00', $this->balance());
    }

    public function test_ticketing_keeps_the_money_spent(): void
    {
        $booking = $this->bookedBooking();

        app(BookingService::class)->transitionTo($booking, BookingStatus::Ticketed);

        $this->assertSame('3600.00', $this->balance());
        $this->assertFalse($booking->fresh()->wasRefundedToWallet());
    }

    public function test_walking_through_two_refunding_statuses_refunds_once(): void
    {
        // Ticketed -> Cancelled -> Refunded passes through two refunding states.
        $booking = $this->bookedBooking();
        $service = app(BookingService::class);

        $service->transitionTo($booking, BookingStatus::Ticketed);
        $service->transitionTo($booking, BookingStatus::Cancelled);
        $service->transitionTo($booking, BookingStatus::Refunded);

        $this->assertSame('10000.00', $this->balance());
        $this->assertSame(
            1,
            $booking->walletTransactions()->where('direction', WalletTransaction::CREDIT)->count(),
            'the charge must be given back exactly once',
        );
    }

    public function test_the_refund_matches_the_original_charge(): void
    {
        $booking = $this->bookedBooking();
        $charge = $booking->walletCharge();

        app(BookingService::class)->transitionTo($booking, BookingStatus::Cancelled);

        $refund = $booking->walletTransactions()->where('direction', WalletTransaction::CREDIT)->firstOrFail();
        $this->assertSame((string) $charge->amount, (string) $refund->amount);
        $this->assertSame("Refund for booking {$booking->reference}", $refund->description);
    }

    public function test_cancelling_an_uncharged_booking_does_not_invent_funds(): void
    {
        // Platform-staff booking: never charged, so nothing to give back.
        $this->fakeQuote();
        $staff = $this->userWith(['flight.view', 'flight.search', 'booking.view', 'booking.create']);
        $this->actingAs($staff)->post(route('bookings.store'), $this->payload())->assertRedirect();

        $booking = Booking::firstOrFail();
        app(BookingService::class)->transitionTo($booking, BookingStatus::Cancelled);

        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_the_balance_still_matches_the_ledger_after_a_refund(): void
    {
        $booking = $this->bookedBooking();
        app(BookingService::class)->transitionTo($booking, BookingStatus::Cancelled);

        $wallets = app(WalletService::class);
        $wallet = $wallets->for($this->agency)->fresh();

        $this->assertSame((string) $wallet->balance, $wallets->ledgerBalance($wallet));
    }
}
