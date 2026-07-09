<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

#[Fillable([
    'reference', 'user_id', 'environment', 'status', 'trace_id', 'result_index',
    'is_lcc', 'pnr', 'booking_id', 'currency', 'total_amount', 'quote', 'pax', 'contact',
])]
class Booking extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'is_lcc' => 'boolean',
            'total_amount' => 'decimal:2',
            'quote' => 'array',
            'pax' => 'array',
            'contact' => 'array',
        ];
    }

    /**
     * A booking's environment is stamped once at creation and can never change —
     * a search/quote/book/ticket flow must stay on ONE environment end-to-end.
     */
    protected static function booted(): void
    {
        static::updating(function (Booking $booking): void {
            if ($booking->isDirty('environment')) {
                throw new RuntimeException("A booking's environment is immutable.");
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
