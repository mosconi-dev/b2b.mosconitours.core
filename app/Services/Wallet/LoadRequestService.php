<?php

namespace App\Services\Wallet;

use App\Enums\LoadRequestStatus;
use App\Exceptions\WalletException;
use App\Models\Agency;
use App\Models\User;
use App\Models\WalletLoadRequest;
use App\Services\Rbac\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The load-request cycle: raise → review → approve/reject, plus cancel by the
 * requester while it is still pending.
 *
 * Who may do each step is decided entirely by permissions (wallet.load.create /
 * .approve / .cancel) — this service enforces only the process rules that no
 * permission can make safe: the status machine, and four-eyes on approval.
 */
class LoadRequestService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{amount: string|float, payment_reference?: string|null, remarks?: string|null}  $data
     */
    public function request(Agency $agency, User $requester, array $data): WalletLoadRequest
    {
        $wallet = $this->wallets->for($agency);
        $amount = number_format((float) $data['amount'], 2, '.', '');

        if (bccomp($amount, '0', 2) <= 0) {
            throw new WalletException('The amount must be greater than zero.');
        }

        $request = WalletLoadRequest::create([
            'reference' => $this->reference(),
            'agency_id' => $agency->id,
            'wallet_id' => $wallet->id,
            'amount' => $amount,
            'currency' => $wallet->currency,
            'status' => LoadRequestStatus::Pending,
            'payment_reference' => $data['payment_reference'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'requested_by' => $requester->getKey(),
        ]);

        $this->audit->log('wallet.load_requested', $request, [
            'agency_id' => $agency->id,
            'amount' => $amount,
        ]);

        return $request;
    }

    /**
     * Approve and credit the wallet — one transaction, so the request never ends up
     * approved without a matching ledger entry (or vice versa).
     */
    public function approve(WalletLoadRequest $request, User $reviewer, ?string $remarks = null): WalletLoadRequest
    {
        $this->guardReviewable($request, LoadRequestStatus::Approved);
        $this->guardNotOwnRequest($request, $reviewer);

        return DB::transaction(function () use ($request, $reviewer, $remarks): WalletLoadRequest {
            // Re-read under lock and re-check: two reviewers clicking Approve at the
            // same moment both passed the check above. The loser sees "already reviewed".
            /** @var WalletLoadRequest $locked */
            $locked = WalletLoadRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            $this->guardReviewable($locked, LoadRequestStatus::Approved);

            $entry = $this->wallets->credit(
                $locked->wallet,
                (string) $locked->amount,
                $reviewer,
                $locked,
                "Wallet load {$locked->reference}",
            );

            $locked->fill([
                'status' => LoadRequestStatus::Approved,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
                'wallet_transaction_id' => $entry->id,
            ])->save();

            $this->audit->log('wallet.load_approved', $locked, [
                'amount' => (string) $locked->amount,
                'balance_after' => (string) $entry->balance_after,
            ], actor: $reviewer);

            return $locked;
        });
    }

    public function reject(WalletLoadRequest $request, User $reviewer, ?string $remarks = null): WalletLoadRequest
    {
        $this->guardReviewable($request, LoadRequestStatus::Rejected);
        $this->guardNotOwnRequest($request, $reviewer);

        $request->fill([
            'status' => LoadRequestStatus::Rejected,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'review_remarks' => $remarks,
        ])->save();

        $this->audit->log('wallet.load_rejected', $request, [
            'amount' => (string) $request->amount,
        ], actor: $reviewer);

        return $request;
    }

    /**
     * Withdrawn by the requester before anyone reviews it. No money has moved.
     */
    public function cancel(WalletLoadRequest $request, User $actor): WalletLoadRequest
    {
        $this->guardReviewable($request, LoadRequestStatus::Cancelled);

        $request->fill([
            'status' => LoadRequestStatus::Cancelled,
            'reviewed_at' => now(),
        ])->save();

        $this->audit->log('wallet.load_cancelled', $request, [], actor: $actor);

        return $request;
    }

    /**
     * The status machine is the guard against a double credit: once approved, a
     * request is terminal and can never be approved again.
     */
    private function guardReviewable(WalletLoadRequest $request, LoadRequestStatus $to): void
    {
        if (! $request->status->canTransitionTo($to)) {
            throw new WalletException(
                "This request is already {$request->status->value} and cannot be {$to->value}."
            );
        }
    }

    /**
     * Four-eyes: holding the approve permission lets you review other people's
     * requests, not sign off your own top-up.
     */
    private function guardNotOwnRequest(WalletLoadRequest $request, User $reviewer): void
    {
        if ($request->requested_by !== null && $request->requested_by === $reviewer->getKey()) {
            throw new WalletException('You cannot review your own load request.');
        }
    }

    private function reference(): string
    {
        return 'LR-'.strtoupper(Str::random(8));
    }
}
