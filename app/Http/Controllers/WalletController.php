<?php

namespace App\Http\Controllers;

use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WalletController extends Controller
{
    public function __construct(private readonly WalletService $wallets) {}

    /**
     * The signed-in user's agency wallet: balance plus the ledger behind it.
     *
     * Platform staff have no agency and therefore no wallet of their own — they
     * see an explanation rather than an empty balance that looks like zero funds.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $agency = $user->agency;

        $wallet = $agency ? $this->wallets->for($agency) : null;

        $entries = $wallet
            ? WalletTransaction::where('wallet_id', $wallet->id)
                ->with('user:id,name')
                ->latest('created_at')
                ->latest('id')
                ->paginate(20)
            : null;

        return view('wallet.index', compact('agency', 'wallet', 'entries'));
    }
}
