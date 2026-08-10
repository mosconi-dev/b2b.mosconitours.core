<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An agency's e-wallet balance. One per agency — never per user, so every member
 * draws on the same funds and a member leaving changes nothing.
 *
 * `balance` is a cached running total; `transactions` is the authoritative
 * append-only ledger. Both are written together inside one locked transaction
 * (see WalletService), so they cannot drift.
 */
#[Fillable(['agency_id', 'currency', 'balance'])]
class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use BelongsToAgency, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // decimal:2 keeps the value a string, so money never touches a float.
        return [
            'balance' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<WalletTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * @return HasMany<WalletLoadRequest, $this>
     */
    public function loadRequests(): HasMany
    {
        return $this->hasMany(WalletLoadRequest::class);
    }

    public function hasAtLeast(string $amount): bool
    {
        return bccomp((string) $this->balance, $amount, 2) >= 0;
    }

    public function formattedBalance(): string
    {
        return number_format((float) $this->balance, 2);
    }
}
