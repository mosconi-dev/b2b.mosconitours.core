<?php

namespace Tests\Feature\Wallet;

use App\Models\Agency;
use App\Services\Wallet\WalletService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The Payment step warns about a shortfall before the agent submits, instead of
 * letting them fill in the whole wizard and be rejected at the end. Advisory only:
 * BookingService still re-checks the balance under lock at submit.
 */
class BookingWalletWarningTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->agency = Agency::factory()->create();
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

    private function fund(string $amount): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->for($this->agency), $amount, null, null, 'Opening balance');
    }

    /**
     * @return array<string, string>
     */
    private function query(): array
    {
        return ['traceId' => 'trace-abc-123', 'resultIndex' => str_repeat('R', 400)];
    }

    public function test_the_wizard_receives_the_agency_balance(): void
    {
        $this->fakeQuote();
        $this->fund('9000.00');
        $agent = $this->agencyUserWith($this->agency, ['flight.view', 'booking.view', 'booking.create']);

        $wallet = $this->actingAs($agent)
            ->get(route('bookings.create', $this->query()))
            ->assertOk()
            ->viewData('wallet');

        $this->assertSame('9000.00', $wallet['balance']);
        $this->assertSame('PHP', $wallet['currency']);
    }

    public function test_an_agency_with_no_wallet_yet_reads_as_zero_without_creating_one(): void
    {
        $this->fakeQuote();
        $agent = $this->agencyUserWith($this->agency, ['flight.view', 'booking.view', 'booking.create']);

        $wallet = $this->actingAs($agent)
            ->get(route('bookings.create', $this->query()))
            ->assertOk()
            ->viewData('wallet');

        $this->assertSame('0.00', $wallet['balance']);
        $this->assertDatabaseMissing('wallets', ['agency_id' => $this->agency->id]);
    }

    public function test_platform_staff_get_no_wallet_block_at_all(): void
    {
        // They are never charged, so a balance warning would be meaningless.
        $this->fakeQuote();
        $staff = $this->userWith(['flight.view', 'booking.view', 'booking.create']);

        $response = $this->actingAs($staff)
            ->get(route('bookings.create', $this->query()))
            ->assertOk();

        // The wizard is handed wallet: null, so hasWallet is false and none of the
        // wallet templates can render. Asserting the markup is absent would be
        // meaningless — it sits inside <template x-if> and is always in the HTML,
        // so the view data is the honest thing to check.
        $this->assertNull($response->viewData('wallet'));
    }

    /**
     * Wiring only. Whether the warning actually shows is an Alpine computation
     * (walletShort), which a server-side assertion cannot reach — the markup lives
     * in <template x-if> and is present either way.
     */
    public function test_the_payment_step_is_wired_to_the_wallet(): void
    {
        $this->fakeQuote();
        $this->fund('100.00');
        $agent = $this->agencyUserWith($this->agency, ['flight.view', 'booking.view', 'booking.create']);

        $response = $this->actingAs($agent)
            ->get(route('bookings.create', $this->query()))
            ->assertOk()
            ->assertSee('Not enough wallet balance')
            ->assertSee('Remaining after booking')
            // Submit is blocked while short.
            ->assertSee('submitting || walletShort', escape: false);

        $this->assertSame('100.00', $response->viewData('wallet')['balance']);
    }

    public function test_the_load_request_link_follows_the_permission(): void
    {
        $this->fakeQuote();
        $this->fund('100.00');

        $canRequest = $this->agencyUserWith($this->agency, [
            'flight.view', 'booking.view', 'booking.create', 'wallet.load.create',
        ]);
        $cannot = $this->agencyUserWith($this->agency, ['flight.view', 'booking.view', 'booking.create']);

        $this->assertSame(
            route('wallet.requests.create'),
            $this->actingAs($canRequest)->get(route('bookings.create', $this->query()))->viewData('walletRequestUrl'),
        );

        $this->assertNull(
            $this->actingAs($cannot)->get(route('bookings.create', $this->query()))->viewData('walletRequestUrl'),
        );
    }

    public function test_the_server_still_refuses_a_booking_the_warning_would_have_blocked(): void
    {
        // The warning is advisory; the balance can change while the page is open.
        $this->fakeQuote();
        $this->fund('100.00');
        $agent = $this->agencyUserWith($this->agency, ['flight.view', 'booking.view', 'booking.create']);

        $this->actingAs($agent)
            ->postJson(route('bookings.store'), [
                'traceId' => 'trace-abc-123',
                'resultIndex' => str_repeat('R', 400),
                'contact' => [
                    'email' => 'agent@example.com', 'phone' => '09170000000', 'mobileCountryCode' => '63',
                    'addressLine1' => '123 Rizal Street', 'city' => 'Makati', 'countryCode' => 'PH',
                ],
                'passengers' => [
                    ['type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz', 'gender' => 'M'],
                ],
            ])
            ->assertStatus(422);
    }
}
