<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WalletTransaction;

class WalletTransactionPolicy
{
    /**
     * Undo one ledger entry by posting its opposite.
     *
     * Refused when the entry is itself a correction (reversing a reversal is
     * confusing — post an adjustment instead) or has already been reversed.
     * WalletService re-checks both under lock, and a unique index backs it.
     */
    public function reverse(User $user, WalletTransaction $entry): bool
    {
        return $user->can('wallet.adjust')
            && $entry->isVisibleTo($user)
            && ! $entry->isReversal()
            && ! $entry->isReversed();
    }
}
