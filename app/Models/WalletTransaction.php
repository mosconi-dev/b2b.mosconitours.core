<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One append-only ledger entry. Never updated or deleted — a correction is a new
 * opposing entry, so the history always reconciles.
 */
#[Fillable([
    'wallet_id', 'agency_id', 'direction', 'amount', 'balance_after',
    'source_type', 'source_id', 'reversed_transaction_id', 'description', 'user_id', 'created_at',
])]
class WalletTransaction extends Model
{
    use BelongsToAgency;

    public const CREDIT = 'credit';

    public const DEBIT = 'debit';

    /** Append-only — only created_at is tracked. */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What caused the entry — a load request today, a booking later.
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The entry this one reverses, if it is a correction.
     *
     * @return BelongsTo<WalletTransaction, $this>
     */
    public function reversed(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_transaction_id');
    }

    /**
     * The correcting entry that reversed this one, if any.
     *
     * @return HasOne<WalletTransaction, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversed_transaction_id');
    }

    /** This entry undoes an earlier one. */
    public function isReversal(): bool
    {
        return $this->reversed_transaction_id !== null;
    }

    /** An earlier correction already undid this entry. */
    public function isReversed(): bool
    {
        return $this->reversal()->exists();
    }

    public function isCredit(): bool
    {
        return $this->direction === self::CREDIT;
    }

    /** The direction that undoes this entry. */
    public function opposingDirection(): string
    {
        return $this->isCredit() ? self::DEBIT : self::CREDIT;
    }

    /**
     * The amount with its sign, for display: +1,000.00 / -250.00
     */
    public function signedAmount(): string
    {
        return ($this->isCredit() ? '+' : '−').number_format((float) $this->amount, 2);
    }
}
