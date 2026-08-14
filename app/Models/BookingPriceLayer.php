<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rung of a booking's price ladder, as it was at the moment of booking.
 *
 * Immutable by convention rather than by constraint: nothing in the application ever
 * updates one, and `$timestamps` is off on the update side because there is no
 * `updated_at` column to write. A correction is a new set of layers, never an edit.
 *
 * `rule_snapshot` is what makes the row self-contained. Read it, not the relations,
 * when explaining a historical price — the rule it points at may since have been
 * changed or deleted, which is exactly the case this table exists to survive.
 */
class BookingPriceLayer extends Model
{
    /** Level 0: the Main Office. Its markup is inside every booking's cost. */
    public const MAIN_OFFICE = 0;

    /** Level 1: the booking agency's own margin, collected from its own customer. */
    public const AGENCY = 1;

    protected $guarded = [];

    /** Append-only — the table has created_at and no updated_at. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'rule_snapshot' => 'array',
            'basis_amount' => 'decimal:2',
            'markup_amount' => 'decimal:2',
            'running_total' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * The agency whose margin this rung is.
     *
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function levelLabel(): string
    {
        return match ($this->level) {
            self::MAIN_OFFICE => 'Main Office',
            self::AGENCY => 'Agency',
            default => "Level {$this->level}",
        };
    }
}
