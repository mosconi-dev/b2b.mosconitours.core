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
 * Manual corrections: reversing a single entry (the fix for a wrong approval) and
 * posting a free adjustment. Both append a new opposing entry — the ledger is never
 * edited, so the mistake and its correction both remain on the record.
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

    // ---- Reversal — the fix for a wrong approval -------------------------

    public function test_reversing_a_credit_takes_the_money_back(): void
    {
        $entry = $this->credit('5000.00');
        $this->assertSame('5000.00', (string) $this->wallet->fresh()->balance);

        $this->actingAs($this->officer())
            ->patch(route('wallet.reverse', $entry), ['reason' => 'Load approved twice'])
            ->assertRedirect();

        $this->assertSame('0.00', (string) $this->wallet->fresh()->balance);
    }

    public function test_a_reversal_is_a_new_entry_and_never_edits_the_original(): void
    {
        $entry = $this->credit('5000.00');

        $this->actingAs($this->officer())
            ->patch(route('wallet.reverse', $entry), ['reason' => 'Duplicate'])
            ->assertRedirect();

        $original = $entry->fresh();
        $this->assertSame('credit', $original->direction, 'the original must be untouched');
        $this->assertSame('5000.00', (string) $original->amount);
        $this->assertTrue($original->isReversed());

        $correction = $original->reversal;
        $this->assertSame('debit', $correction->direction);
        $this->assertSame('5000.00', (string) $correction->amount);
        $this->assertSame('Duplicate', $correction->description);
        $this->assertSame($this->wallet->id, $correction->wallet_id);
        $this->assertSame(2, $this->wallet->transactions()->count());
    }

    public function test_the_same_entry_cannot_be_reversed_twice(): void
    {
        $entry = $this->credit('1000.00');
        $officer = $this->officer();

        $this->actingAs($officer)->patch(route('wallet.reverse', $entry), ['reason' => 'First'])->assertRedirect();
        // No longer reversible, so the policy refuses the replay outright.
        $this->actingAs($officer)->patch(route('wallet.reverse', $entry), ['reason' => 'Second'])->assertForbidden();

        $this->assertSame('0.00', (string) $this->wallet->fresh()->balance);
        $this->assertSame(2, $this->wallet->transactions()->count());
    }

    public function test_a_correction_cannot_itself_be_reversed(): void
    {
        $entry = $this->credit('1000.00');
        $officer = $this->officer();

        $this->actingAs($officer)->patch(route('wallet.reverse', $entry), ['reason' => 'Wrong'])->assertRedirect();
        $correction = $entry->fresh()->reversal;

        $this->actingAs($officer)
            ->patch(route('wallet.reverse', $correction), ['reason' => 'Undo the undo'])
            ->assertForbidden();
    }

    public function test_a_reversal_may_drive_the_balance_negative(): void
    {
        // 5,000 credited in error, 3,000 already spent. The claw-back is still owed:
        // refusing to record it would leave the books wrong.
        $entry = $this->credit('5000.00');
        app(WalletService::class)->debit($this->wallet, '3000.00', null, null, 'Spent');

        $this->actingAs($this->officer())
            ->patch(route('wallet.reverse', $entry), ['reason' => 'Credited in error'])
            ->assertRedirect();

        $this->assertSame('-3000.00', (string) $this->wallet->fresh()->balance);
    }

    public function test_a_reason_is_required(): void
    {
        $entry = $this->credit();

        $this->actingAs($this->officer())
            ->patch(route('wallet.reverse', $entry), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame('5000.00', (string) $this->wallet->fresh()->balance);
    }

    public function test_reversing_requires_the_adjust_permission(): void
    {
        $entry = $this->credit();
        $viewer = $this->userWith(['wallet.view']);

        $this->actingAs($viewer)
            ->patch(route('wallet.reverse', $entry), ['reason' => 'Nope'])
            ->assertForbidden();

        $this->assertSame('5000.00', (string) $this->wallet->fresh()->balance);
    }

    public function test_an_agency_cannot_reverse_another_agencys_entry(): void
    {
        $entry = $this->credit();
        $outsider = $this->agencyUserWith(Agency::factory()->create(), ['wallet.view', 'wallet.adjust']);

        $this->actingAs($outsider)
            ->patch(route('wallet.reverse', $entry), ['reason' => 'Not mine'])
            ->assertForbidden();
    }

    public function test_the_reversal_is_audited(): void
    {
        $entry = $this->credit('750.00');

        $this->actingAs($this->officer())
            ->patch(route('wallet.reverse', $entry), ['reason' => 'Duplicate load'])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['event' => 'wallet.reversed']);
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

        $a = $this->credit('1000.00');
        $this->credit('250.50');
        $this->actingAs($officer)->patch(route('wallet.reverse', $a), ['reason' => 'Wrong'])->assertRedirect();
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
            ->assertSee('Manual adjustment')
            ->assertSee('Reverse');
    }

    public function test_the_wallet_tab_hides_corrections_without_the_permission(): void
    {
        $this->credit('1500.00');
        $viewer = $this->userWith(['agency.view', 'wallet.view']);

        $this->actingAs($viewer)
            ->get(route('admin.agencies.show', ['agency' => $this->agency, 'tab' => 'wallet']))
            ->assertOk()
            ->assertSee('1,500.00')
            ->assertDontSee('Manual adjustment')
            ->assertDontSee('>Reverse<', escape: false);
    }
}
