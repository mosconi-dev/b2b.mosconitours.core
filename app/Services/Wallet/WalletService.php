<?php

namespace App\Services\Wallet;

use App\Exceptions\WalletException;
use App\Models\Agency;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of wallet balances.
 *
 * Every movement goes through post(): one locked transaction that appends a
 * ledger row and updates the cached balance together, so `wallets.balance` can
 * never disagree with the sum of `wallet_transactions`.
 *
 * All arithmetic is bcmath on decimal strings — money never touches a float.
 */
class WalletService
{
    private const SCALE = 2;

    /**
     * The agency's wallet, created on first use so no agency is ever without one.
     */
    public function for(Agency $agency): Wallet
    {
        return Wallet::firstOrCreate(
            ['agency_id' => $agency->id],
            ['currency' => 'PHP', 'balance' => '0.00'],
        );
    }

    public function credit(Wallet $wallet, string $amount, ?User $actor = null, ?Model $source = null, ?string $description = null): WalletTransaction
    {
        return $this->post($wallet, WalletTransaction::CREDIT, $amount, $actor, $source, $description);
    }

    public function debit(Wallet $wallet, string $amount, ?User $actor = null, ?Model $source = null, ?string $description = null): WalletTransaction
    {
        return $this->post($wallet, WalletTransaction::DEBIT, $amount, $actor, $source, $description);
    }

    /**
     * Append one ledger entry and move the balance with it.
     *
     * The wallet row is locked for the duration, so two concurrent debits cannot
     * both read the same balance and overdraw it — the second waits, then re-reads
     * the balance the first one wrote.
     */
    private function post(Wallet $wallet, string $direction, string $amount, ?User $actor, ?Model $source, ?string $description): WalletTransaction
    {
        $amount = $this->normalize($amount);

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw new WalletException('The amount must be greater than zero.');
        }

        return DB::transaction(function () use ($wallet, $direction, $amount, $actor, $source, $description): WalletTransaction {
            /** @var Wallet $locked */
            $locked = Wallet::whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();

            $balance = $this->normalize((string) $locked->balance);

            if ($direction === WalletTransaction::DEBIT && bccomp($balance, $amount, self::SCALE) < 0) {
                throw new WalletException(
                    'Insufficient wallet balance: '.number_format((float) $balance, 2).
                    ' available, '.number_format((float) $amount, 2).' required.'
                );
            }

            $balanceAfter = $direction === WalletTransaction::CREDIT
                ? bcadd($balance, $amount, self::SCALE)
                : bcsub($balance, $amount, self::SCALE);

            $entry = WalletTransaction::create([
                'wallet_id' => $locked->id,
                'agency_id' => $locked->agency_id,
                'direction' => $direction,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'description' => $description,
                'user_id' => $actor?->getKey(),
                'created_at' => now(),
            ]);

            $locked->balance = $balanceAfter;
            $locked->save();

            // Keep the caller's instance in step with what was just written.
            $wallet->setRawAttributes($locked->getAttributes(), true);

            return $entry;
        });
    }

    /**
     * Sum of the ledger — the independent check that `wallets.balance` is honest.
     * Used by tests and available for a reconciliation report.
     */
    public function ledgerBalance(Wallet $wallet): string
    {
        $credits = $this->normalize((string) $wallet->transactions()->where('direction', WalletTransaction::CREDIT)->sum('amount'));
        $debits = $this->normalize((string) $wallet->transactions()->where('direction', WalletTransaction::DEBIT)->sum('amount'));

        return bcsub($credits, $debits, self::SCALE);
    }

    /**
     * Force a value to a plain fixed-scale decimal string, so a float, an int or a
     * "1,000.00" never reaches bcmath (which would silently read it as 1).
     */
    private function normalize(string|float|int $amount): string
    {
        return number_format((float) str_replace(',', '', (string) $amount), self::SCALE, '.', '');
    }
}
