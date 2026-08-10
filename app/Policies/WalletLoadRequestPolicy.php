<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WalletLoadRequest;

/**
 * Who may do each step is the permission's job. This policy adds only what a
 * permission cannot express: agency scope, the pending-state rule, and four-eyes
 * on approval.
 */
class WalletLoadRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('wallet.load.view');
    }

    public function view(User $user, WalletLoadRequest $request): bool
    {
        return $user->can('wallet.load.view') && $request->isVisibleTo($user);
    }

    /**
     * A request loads an agency's wallet, so the requester must belong to one.
     * Platform staff have no wallet of their own to top up.
     */
    public function create(User $user): bool
    {
        return $user->can('wallet.load.create') && ! $user->isPlatformStaff();
    }

    /**
     * Approve or reject — one permission covers both, since they are the two
     * outcomes of the same act of reviewing.
     *
     * Four-eyes: holding the permission lets you review other people's requests,
     * not sign off your own top-up. LoadRequestService enforces this again.
     */
    public function review(User $user, WalletLoadRequest $request): bool
    {
        return $user->can('wallet.load.approve')
            && $request->isVisibleTo($user)
            && $request->isPending()
            && $request->requested_by !== $user->getKey();
    }

    public function cancel(User $user, WalletLoadRequest $request): bool
    {
        return $user->can('wallet.load.cancel')
            && $request->isVisibleTo($user)
            && $request->isPending();
    }
}
