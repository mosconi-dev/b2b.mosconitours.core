<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The hotel detail behind a booking.
 *
 * Everything here is a copy taken at booking time, not a join. A voucher presented at
 * a front desk eighteen months later must read the same as the day it was issued, and
 * the catalogue row behind it may have been re-synced, renamed or dropped since.
 */
class HotelBooking extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'nights' => 'integer',
            'rooms_count' => 'integer',
            'rating' => 'integer',
            'is_refundable' => 'boolean',
            'with_transfers' => 'boolean',
            'room_names' => 'array',
            'cancel_policies' => 'array',
            'supplements' => 'array',
            'rate_conditions' => 'array',
            'amenities' => 'array',
            'hcn_attempts' => 'integer',
            'hcn_next_attempt_at' => 'datetime',
            'cancellation_charge' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * What the guest settles at the property, on top of what we charged.
     *
     * @return array<int, array<string, mixed>>
     */
    public function payableAtProperty(): array
    {
        return array_values(array_filter(
            $this->supplements ?? [],
            fn (array $s): bool => ($s['type'] ?? '') === 'AtProperty',
        ));
    }

    /**
     * Whether the hotel's own reference is still outstanding.
     *
     * TBO only issues it once check-in is within 30 days, so a booking made further
     * out is not late — it is simply not due yet.
     */
    public function awaitingHotelConfirmation(): bool
    {
        return blank($this->hotel_confirmation_number) && filled($this->confirmation_number);
    }
}
