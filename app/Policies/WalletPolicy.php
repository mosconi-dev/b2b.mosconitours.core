<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Wallet;

class WalletPolicy
{
    public function view(User $user, Wallet $wallet): bool
    {
        return $user->can('wallet.view') && $wallet->isVisibleTo($user);
    }

    /**
     * Post a manual correction against this wallet.
     */
    public function adjust(User $user, Wallet $wallet): bool
    {
        return $user->can('wallet.adjust') && $wallet->isVisibleTo($user);
    }
}
