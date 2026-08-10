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
     * Undo one entry by posting its exact opposite, linked back to the original.
     *
     * This is the correction for a load approved in error: the amount and direction
     * come from the entry itself, so there is nothing to mistype. Neither row is
     * edited — the mistake and its correction both stay on the ledger.
     *
     * A reversal may drive the balance negative. If funds were credited in error and
     * partly spent, the claw-back is still owed, and refusing to record it would
     * leave the books wrong rather than merely uncomfortable.
     */
    public function reverse(WalletTransaction $entry, User $actor, string $reason): WalletTransaction
    {
        if ($entry->isReversal()) {
            throw new WalletException('This entry is itself a correction and cannot be reversed. Post an adjustment instead.');
        }

        if ($entry->isReversed()) {
            throw new WalletException('This entry has already been reversed.');
        }

        return DB::transaction(function () use ($entry, $actor, $reason): WalletTransaction {
            // Re-check under lock: two people clicking Reverse together both passed above.
            /** @var WalletTransaction $locked */
            $locked = WalletTransaction::whereKey($entry->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isReversed()) {
                throw new WalletException('This entry has already been reversed.');
            }

            return $this->post(
                $locked->wallet,
                $locked->opposingDirection(),
                (string) $locked->amount,
                $actor,
                null,
                $reason,
                allowNegative: true,
                reverses: $locked,
            );
        });
    }

    /**
     * A discretionary correction not tied to an existing entry — a fee, a goodwill
     * credit, a balance brought over from elsewhere. Always carries a reason.
     *
     * Also allowed to go negative: someone correcting the books should not be
     * blocked by the very balance they are correcting.
     */
    public function adjust(Wallet $wallet, string $direction, string $amount, User $actor, string $reason): WalletTransaction
    {
        if (! in_array($direction, [WalletTransaction::CREDIT, WalletTransaction::DEBIT], true)) {
            throw new WalletException('An adjustment must be either a credit or a debit.');
        }

        return $this->post($wallet, $direction, $amount, $actor, null, $reason, allowNegative: true);
    }

    /**
     * Append one ledger entry and move the balance with it.
     *
     * The wallet row is locked for the duration, so two concurrent debits cannot
     * both read the same balance and overdraw it — the second waits, then re-reads
     * the balance the first one wrote.
     */
    private function post(Wallet $wallet, string $direction, string $amount, ?User $actor, ?Model $source, ?string $description, bool $allowNegative = false, ?WalletTransaction $reverses = null): WalletTransaction
    {
        $amount = $this->normalize($amount);

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw new WalletException('The amount must be greater than zero.');
        }

        return DB::transaction(function () use ($wallet, $direction, $amount, $actor, $source, $description, $allowNegative, $reverses): WalletTransaction {
            /** @var Wallet $locked */
            $locked = Wallet::whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();

            $balance = $this->normalize((string) $locked->balance);

            // Corrections bypass this on purpose (see reverse()/adjust()); ordinary
            // operational debits must never overdraw.
            if (! $allowNegative && $direction === WalletTransaction::DEBIT && bccomp($balance, $amount, self::SCALE) < 0) {
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
                'reversed_transaction_id' => $reverses?->getKey(),
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
