<?php

namespace App\Enums;

/**
 * Lifecycle of a flight booking. Transitions are guarded so a retry or an
 * out-of-order call can never move a booking into an illegal state.
 */
enum BookingStatus: string
{
    case Quoted = 'quoted';       // priced + passengers captured; nothing sent to TBO yet
    case Booked = 'booked';       // PNR held (non-LCC)
    case Ticketed = 'ticketed';   // issued
    case Failed = 'failed';       // a booking/ticketing attempt failed
    case Cancelled = 'cancelled'; // released / voided
    case Refunded = 'refunded';

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Quoted => [self::Booked, self::Ticketed, self::Failed, self::Cancelled],
            self::Booked => [self::Ticketed, self::Failed, self::Cancelled],
            self::Ticketed => [self::Cancelled, self::Refunded],
            self::Cancelled => [self::Refunded],
            self::Failed, self::Refunded => [],
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
            self::Ticketed => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::Booked => 'bg-blue-50 text-blue-700 ring-blue-600/20',
            self::Quoted => 'bg-gray-100 text-gray-600 ring-gray-500/20',
            self::Cancelled => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            self::Refunded => 'bg-violet-50 text-violet-700 ring-violet-600/20',
            self::Failed => 'bg-red-50 text-red-700 ring-red-600/20',
        };
    }
}
