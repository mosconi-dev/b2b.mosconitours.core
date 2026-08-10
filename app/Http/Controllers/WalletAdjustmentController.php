<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWalletAdjustmentRequest;
use App\Models\Wallet;
use App\Services\Rbac\AuditLogger;
use App\Services\Wallet\WalletService;
use Illuminate\Http\RedirectResponse;

/**
 * Manual corrections to a wallet. Posts a NEW ledger entry — nothing is ever
 * edited or removed, so the mistake and its correction both remain visible.
 */
class WalletAdjustmentController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly AuditLogger $audit,
    ) {}

    public function store(StoreWalletAdjustmentRequest $request, Wallet $wallet): RedirectResponse
    {
        $entry = $this->wallets->adjust(
            $wallet,
            $request->validated('direction'),
            $request->validated('amount'),
            $request->user(),
            $request->validated('reason'),
        );

        $this->audit->log('wallet.adjusted', $entry, [
            'agency_id' => $wallet->agency_id,
            'direction' => $entry->direction,
            'amount' => (string) $entry->amount,
            'balance_after' => (string) $entry->balance_after,
            'reason' => $request->validated('reason'),
        ]);

        return back()->with('status', "Adjustment posted: {$entry->signedAmount()}. New balance {$wallet->fresh()->formattedBalance()}.");
    }
}
