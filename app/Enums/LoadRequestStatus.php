<?php

namespace App\Enums;

/**
 * Lifecycle of a wallet load request. Transitions are guarded so a retry or a
 * double-click can never move a request into an illegal state — and, crucially,
 * can never credit the wallet twice.
 */
enum LoadRequestStatus: string
{
    case Pending = 'pending';       // submitted, awaiting review
    case Approved = 'approved';     // funds confirmed; the wallet has been credited
    case Rejected = 'rejected';     // reviewed and declined
    case Cancelled = 'cancelled';   // withdrawn by the requester before review

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected, self::Cancelled],
            // Terminal: an approved request has moved money and is never revisited.
            // Reversing one is a separate ledger entry, not a status change.
            self::Approved, self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Tailwind pill classes for rendering the status as a badge.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Approved => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::Pending => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            self::Rejected => 'bg-red-50 text-red-700 ring-red-600/20',
            self::Cancelled => 'bg-gray-100 text-gray-600 ring-gray-500/20',
        };
    }
}
