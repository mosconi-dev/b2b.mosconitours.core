<?php

namespace Tests\Feature\Wallet;

use App\Exceptions\WalletException;
use App\Models\Agency;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Manual adjustment — the one way to move money outside the load-request cycle.
 * It appends a new ledger entry; nothing is ever edited, so a correction and the
 * entry it corrects both remain on the record.
 */
class WalletAdjustmentTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    private Agency $agency;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();

        $this->agency = Agency::factory()->create(['name' => 'Acme Travel']);
        $this->wallet = app(WalletService::class)->for($this->agency);
    }

    private function officer(): User
    {
        return $this->userWith(['wallet.view', 'wallet.adjust']);
    }

    private function credit(string $amount = '5000.00', ?string $description = 'Wallet load LR-TEST'): WalletTransaction
    {
        return app(WalletService::class)->credit($this->wallet, $amount, null, null, $description);
    }

    // ---- Free adjustment -------------------------------------------------

    public function test_a_credit_adjustment_adds_funds(): void
    {
        $this->actingAs($this->officer())
            ->post(route('wallet.adjust', $this->wallet), [
                'direction' => 'credit',
                'amount' => '250.00',
                'reason' => 'Goodwill',
            ])
            ->assertRedirect();

        $this->assertSame('250.00', (string) $this->wallet->fresh()->balance);
        $this->assertDatabaseHas('audit_logs', ['event' => 'wallet.adjusted']);
    }

    public function test_a_debit_adjustment_may_go_negative(): void
    {
        $this->actingAs($this->officer())
            ->post(route('wallet.adjust', $this->wallet), [
                'direction' => 'debit',
                'amount' => '100.00',
                'reason' => 'Correcting an over-credit',
            ])
            ->assertRedirect();

        $this->assertSame('-100.00', (string) $this->wallet->fresh()->balance);
    }

    public function test_an_adjustment_needs_a_reason_and_a_valid_amount(): void
    {
        $officer = $this->officer();

        $this->actingAs($officer)
            ->post(route('wallet.adjust', $this->wallet), ['direction' => 'credit', 'amount' => '10.00'])
            ->assertSessionHasErrors('reason');

        $this->actingAs($officer)
            ->post(route('wallet.adjust', $this->wallet), ['direction' => 'credit', 'amount' => '0', 'reason' => 'x'])
            ->assertSessionHasErrors();

        $this->actingAs($officer)
            ->post(route('wallet.adjust', $this->wallet), ['direction' => 'sideways', 'amount' => '10.00', 'reason' => 'Test'])
            ->assertSessionHasErrors('direction');

        $this->assertSame('0.00', (string) $this->wallet->fresh()->balance);
    }

    public function test_adjusting_requires_the_permission(): void
    {
        $this->actingAs($this->userWith(['wallet.view']))
            ->post(route('wallet.adjust', $this->wallet), [
                'direction' => 'credit', 'amount' => '999.00', 'reason' => 'Nope',
            ])
            ->assertForbidden();

        $this->assertSame('0.00', (string) $this->wallet->fresh()->balance);
    }

    // ---- The ledger still reconciles ------------------------------------

    public function test_the_balance_matches_the_ledger_after_corrections(): void
    {
        $service = app(WalletService::class);
        $officer = $this->officer();

        $this->credit('1000.00');
        $this->credit('250.50');
        // Undo the first load with an offsetting debit — the correction path now that
        // entry-level reversal is gone.
        $this->actingAs($officer)->post(route('wallet.adjust', $this->wallet), [
            'direction' => 'debit', 'amount' => '1000.00', 'reason' => 'Load approved in error',
        ])->assertRedirect();
        $this->actingAs($officer)->post(route('wallet.adjust', $this->wallet), [
            'direction' => 'debit', 'amount' => '0.50', 'reason' => 'Rounding',
        ])->assertRedirect();

        $this->wallet->refresh();

        $this->assertSame('250.00', (string) $this->wallet->balance);
        $this->assertSame((string) $this->wallet->balance, $service->ledgerBalance($this->wallet));
    }

    public function test_the_service_refuses_an_unknown_direction(): void
    {
        $this->expectException(WalletException::class);

        app(WalletService::class)->adjust($this->wallet, 'sideways', '10.00', $this->officer(), 'Test');
    }

    // ---- Where it lives in the UI ---------------------------------------

    public function test_the_agency_wallet_tab_shows_the_ledger_and_adjust_form(): void
    {
        $this->credit('1500.00');
        $officer = $this->userWith(['agency.view', 'wallet.view', 'wallet.adjust']);

        $this->actingAs($officer)
            ->get(route('admin.agencies.show', ['agency' => $this->agency, 'tab' => 'wallet']))
            ->assertOk()
            ->assertSee('1,500.00')
            ->assertSee('Manual adjustment');
    }

    public function test_the_wallet_tab_hides_corrections_without_the_permission(): void
    {
        $this->credit('1500.00');
        $viewer = $this->userWith(['agency.view', 'wallet.view']);

        $this->actingAs($viewer)
            ->get(route('admin.agencies.show', ['agency' => $this->agency, 'tab' => 'wallet']))
            ->assertOk()
            ->assertSee('1,500.00')
            ->assertDontSee('Manual adjustment');
    }
}
