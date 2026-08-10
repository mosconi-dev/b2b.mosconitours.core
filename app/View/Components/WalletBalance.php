<?php

namespace App\View\Components;

use App\Models\Wallet;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

/**
 * The agency wallet balance in the top bar.
 *
 * Bookings are charged against this balance, so an agent who cannot see it only
 * finds out they are short after filling in the whole booking wizard. Showing it
 * on every page turns that into something they can see before starting.
 */
class WalletBalance extends Component
{
    public ?Wallet $wallet = null;

    private bool $visible = false;

    public function __construct()
    {
        $user = Auth::user();

        // Platform staff have no agency and therefore no wallet of their own.
        $this->visible = $user !== null
            && $user->agency_id !== null
            && $user->can('wallet.view');

        if ($this->visible) {
            // Read-only on purpose: a GET must not create rows, and an agency that
            // has never loaded genuinely has a zero balance.
            $this->wallet = Wallet::where('agency_id', $user->agency_id)
                ->first(['id', 'agency_id', 'currency', 'balance']);
        }
    }

    public function shouldRender(): bool
    {
        return $this->visible;
    }

    /**
     * Fixed scale so the figure matches what the wallet is actually debited.
     */
    public function formatted(): string
    {
        return number_format((float) ($this->wallet?->balance ?? 0), 2);
    }

    public function currency(): string
    {
        return $this->wallet?->currency ?? 'PHP';
    }

    /**
     * Manual adjustments may take a wallet below zero, so this is a real state.
     */
    public function isNegative(): bool
    {
        return bccomp((string) ($this->wallet?->balance ?? '0'), '0', 2) < 0;
    }

    public function render(): View
    {
        return view('components.wallet-balance');
    }
}
