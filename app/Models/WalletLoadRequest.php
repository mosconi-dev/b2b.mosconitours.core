<?php

namespace App\Models;

use App\Enums\LoadRequestStatus;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\WalletLoadRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to top up an agency's wallet: raised by a member, reviewed by whoever
 * holds the approve permission, and — on approval — credited to the wallet as a
 * single ledger entry recorded on `wallet_transaction_id`.
 */
#[Fillable([
    'reference', 'agency_id', 'wallet_id', 'amount', 'currency', 'status',
    'payment_reference', 'remarks', 'requested_by', 'reviewed_by', 'reviewed_at',
    'review_remarks', 'wallet_transaction_id',
])]
class WalletLoadRequest extends Model
{
    /** @use HasFactory<WalletLoadRequestFactory> */
    use BelongsToAgency, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LoadRequestStatus::class,
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
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
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The ledger entry this request produced, if it was approved.
     *
     * @return BelongsTo<WalletTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function formattedAmount(): string
    {
        return number_format((float) $this->amount, 2);
    }
}
