<?php

namespace App\Models;

use App\Enums\BookingProduct;
use App\Enums\BookingStatus;
use App\Enums\Supplier;
use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

#[Fillable([
    'reference', 'product', 'supplier', 'user_id', 'agency_id', 'environment', 'status', 'trace_id',
    'result_index', 'is_lcc', 'pnr', 'booking_id', 'supplier_reference', 'currency', 'total_amount',
    'ancillary_total', 'quote', 'quote_raw', 'seats_available', 'result_type', 'pax', 'contact',
])]
class Booking extends Model
{
    use BelongsToAgency, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product' => BookingProduct::class,
            'supplier' => Supplier::class,
            'status' => BookingStatus::class,
            'is_lcc' => 'boolean',
            'total_amount' => 'decimal:2',
            'ancillary_total' => 'decimal:2',
            'quote' => 'array',
            'quote_raw' => 'array',
            'seats_available' => 'array',
            'result_type' => 'integer',
            'pax' => 'array',
            'contact' => 'array',
        ];
    }

    /**
     * Matches the column defaults, so an unsaved row reads the same as a saved one.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'product' => BookingProduct::Flight->value,
        'supplier' => Supplier::TboAir->value,
    ];

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

    /**
     * The hotel detail, on hotel bookings only.
     *
     * A one-to-one rather than columns on this table: a flight booking has no use for
     * check-in dates or cancellation policies, and a nullable column per hotel field
     * would make the shared spine mostly empty.
     *
     * @return HasOne<HotelBooking, $this>
     */
    public function hotel(): HasOne
    {
        return $this->hasOne(HotelBooking::class);
    }

    public function isHotel(): bool
    {
        return $this->product === BookingProduct::Hotel;
    }

    /**
     * Wallet movements caused by this booking — the charge, and its refund if the
     * booking was later cancelled, failed or refunded.
     *
     * @return MorphMany<WalletTransaction, $this>
     */
    public function walletTransactions(): MorphMany
    {
        return $this->morphMany(WalletTransaction::class, 'source');
    }

    /**
     * The debit taken when this booking was made, if the booker had an agency.
     */
    public function walletCharge(): ?WalletTransaction
    {
        return $this->walletTransactions()->where('direction', WalletTransaction::DEBIT)->first();
    }

    /**
     * Whether the charge has already been given back — the guard that stops a
     * booking being refunded twice as it moves through terminal states.
     */
    public function wasRefundedToWallet(): bool
    {
        return $this->walletTransactions()->where('direction', WalletTransaction::CREDIT)->exists();
    }
}
