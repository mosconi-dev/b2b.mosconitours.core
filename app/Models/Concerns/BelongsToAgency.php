<?php

namespace App\Models\Concerns;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For history rows that carry a denormalized `agency_id` stamped at creation
 * (bookings, audit logs, API logs).
 *
 * The agency is read from the row, never resolved through the actor's current
 * agency — a user who transfers must not drag their history with them.
 */
trait BelongsToAgency
{
    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * Platform staff see every row; an agency member sees only their own agency's.
     *
     * A NULL agency_id (a platform-staff action, or an agency since hard-deleted)
     * falls outside every member's scope, so this fails closed.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isPlatformStaff()) {
            return;
        }

        $query->where($this->getTable().'.agency_id', $user->agency_id);
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->isPlatformStaff() || $this->agency_id === $user->agency_id;
    }
}
