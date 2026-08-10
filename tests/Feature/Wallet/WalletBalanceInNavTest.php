<?php

namespace Tests\Feature\Wallet;

use App\Models\Agency;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The agency balance in the top bar — visible on every page so an agent knows
 * where they stand before starting a booking, not after finishing one.
 */
class WalletBalanceInNavTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();

        $this->agency = Agency::factory()->create();
    }

    private function fund(string $amount): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->for($this->agency), $amount, null, null, 'Opening balance');
    }

    public function test_a_member_sees_their_agency_balance(): void
    {
        $this->fund('12345.67');
        $member = $this->agencyUserWith($this->agency, ['wallet.view']);

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('12,345.67')
            ->assertSee('title="Agency wallet balance"', escape: false);
    }

    public function test_it_appears_on_every_page_not_just_the_wallet(): void
    {
        $this->fund('500.00');
        $member = $this->agencyUserWith($this->agency, ['wallet.view', 'flight.view']);

        $this->actingAs($member)->get('/dashboard')->assertOk()->assertSee('500.00');
        $this->actingAs($member)->get(route('flights'))->assertOk()->assertSee('500.00');
    }

    public function test_an_agency_with_no_wallet_yet_shows_zero_and_creates_nothing(): void
    {
        $member = $this->agencyUserWith($this->agency, ['wallet.view']);

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('0.00');

        // A GET must never write: the wallet row is still absent.
        $this->assertSame(0, Wallet::where('agency_id', $this->agency->id)->count());
    }

    public function test_a_negative_balance_is_flagged(): void
    {
        // Manual adjustments can overdraw a wallet, so this is a real state.
        $wallets = app(WalletService::class);
        $wallet = $wallets->for($this->agency);
        $wallets->adjust($wallet, 'debit', '250.00', $this->admin(), 'Correcting an over-credit');

        $member = $this->agencyUserWith($this->agency, ['wallet.view']);

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('-250.00')
            ->assertSee('overdrawn');
    }

    public function test_platform_staff_see_nothing(): void
    {
        $staff = $this->userWith(['wallet.view']);

        $this->actingAs($staff)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('title="Agency wallet balance"', escape: false);
    }

    public function test_a_member_without_the_permission_sees_nothing(): void
    {
        $this->fund('999.00');
        $member = $this->agencyUserWith($this->agency, ['flight.view']);

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('title="Agency wallet balance"', escape: false)
            ->assertDontSee('999.00');
    }

    public function test_the_figure_tracks_spending(): void
    {
        $this->fund('10000.00');
        $member = $this->agencyUserWith($this->agency, ['wallet.view']);

        $wallets = app(WalletService::class);
        $wallets->debit($wallets->for($this->agency), '6400.75', null, null, 'Booking');

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('3,599.25');
    }
}
