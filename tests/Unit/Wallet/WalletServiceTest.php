<?php

namespace Tests\Unit\Wallet;

use App\Exceptions\WalletException;
use App\Models\Agency;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): WalletService
    {
        return app(WalletService::class);
    }

    public function test_a_wallet_is_created_once_per_agency(): void
    {
        $agency = Agency::factory()->create();

        $first = $this->service()->for($agency);
        $second = $this->service()->for($agency);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Wallet::where('agency_id', $agency->id)->count());
        $this->assertSame('0.00', (string) $first->balance);
    }

    public function test_a_credit_moves_the_balance_and_writes_a_ledger_entry(): void
    {
        $wallet = Wallet::factory()->create();

        $entry = $this->service()->credit($wallet, '1500.50');

        $this->assertSame('1500.50', (string) $wallet->fresh()->balance);
        $this->assertSame('credit', $entry->direction);
        $this->assertSame('1500.50', (string) $entry->amount);
        $this->assertSame('1500.50', (string) $entry->balance_after);
        $this->assertSame($wallet->agency_id, $entry->agency_id);
    }

    public function test_a_debit_reduces_the_balance(): void
    {
        $wallet = Wallet::factory()->withBalance('2000.00')->create();

        $entry = $this->service()->debit($wallet, '750.25');

        $this->assertSame('1249.75', (string) $wallet->fresh()->balance);
        $this->assertSame('1249.75', (string) $entry->balance_after);
    }

    public function test_a_debit_beyond_the_balance_is_refused(): void
    {
        $wallet = Wallet::factory()->withBalance('100.00')->create();

        $this->expectException(WalletException::class);

        try {
            $this->service()->debit($wallet, '100.01');
        } finally {
            // Nothing moved, and no half-written ledger row survived.
            $this->assertSame('100.00', (string) $wallet->fresh()->balance);
            $this->assertSame(0, $wallet->transactions()->count());
        }
    }

    public function test_a_debit_of_the_exact_balance_is_allowed(): void
    {
        $wallet = Wallet::factory()->withBalance('100.00')->create();

        $this->service()->debit($wallet, '100.00');

        $this->assertSame('0.00', (string) $wallet->fresh()->balance);
    }

    public function test_zero_and_negative_amounts_are_refused(): void
    {
        $wallet = Wallet::factory()->withBalance('50.00')->create();

        foreach (['0', '0.00', '-5.00'] as $amount) {
            try {
                $this->service()->credit($wallet, $amount);
                $this->fail("Expected {$amount} to be refused.");
            } catch (WalletException) {
                // expected
            }
        }

        $this->assertSame('50.00', (string) $wallet->fresh()->balance);
    }

    public function test_the_cached_balance_always_matches_the_ledger(): void
    {
        $wallet = Wallet::factory()->create();
        $service = $this->service();

        // Amounts chosen to expose float drift if the arithmetic were not exact.
        $service->credit($wallet, '0.10');
        $service->credit($wallet, '0.20');
        $service->debit($wallet, '0.30');
        $service->credit($wallet, '1234.56');
        $service->debit($wallet, '0.07');

        $wallet->refresh();

        $this->assertSame('1234.49', (string) $wallet->balance);
        $this->assertSame((string) $wallet->balance, $service->ledgerBalance($wallet));
    }

    public function test_a_thousand_separated_amount_is_not_silently_truncated(): void
    {
        // bcmath would read "1,500.00" as 1 — normalize() must strip the separator.
        $wallet = Wallet::factory()->create();

        $this->service()->credit($wallet, '1,500.00');

        $this->assertSame('1500.00', (string) $wallet->fresh()->balance);
    }

    public function test_the_entry_records_its_actor_and_source(): void
    {
        $wallet = Wallet::factory()->create();
        $actor = User::factory()->create();
        $source = Agency::factory()->create();

        $entry = $this->service()->credit($wallet, '10.00', $actor, $source, 'Manual top-up');

        $this->assertSame($actor->id, $entry->user_id);
        $this->assertSame($source->getMorphClass(), $entry->source_type);
        $this->assertSame($source->id, $entry->source_id);
        $this->assertSame('Manual top-up', $entry->description);
    }
}
