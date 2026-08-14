<?php

namespace App\Models;

use App\Enums\BookingProduct;
use App\Enums\BookingStatus;
use App\Enums\Supplier;
use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

#[Fillable([
    'reference', 'product', 'supplier', 'user_id', 'agency_id', 'environment', 'status', 'trace_id',
    'result_index', 'is_lcc', 'pnr', 'booking_id', 'supplier_reference', 'currency',
    'net_amount', 'cost_amount', 'total_amount', 'markup_total',
    'ancillary_total', 'quote', 'quote_raw', 'seats_available', 'result_type', 'pax', 'contact',
])]
class Booking extends Model
{
    use BelongsToAgency, HasFactory, SoftDeletes;

    /**
     * How many past bookings the flight and hotel search pages show above the form.
     *
     * A shortcut back into recent work, not a list: the list is /bookings, and the
     * panel says so.
     */
    public const RECENT = 4;

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
            'net_amount' => 'decimal:2',
            'cost_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'markup_total' => 'decimal:2',
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
     * Free-text lookup over the identifiers an agent actually has to hand: the
     * reference they were given, the supplier's own number, the traveller's name,
     * the property.
     *
     * Traveller names are matched against the `pax` JSON as text rather than through
     * JSON paths — the array is small, and a path match would have to walk an unknown
     * number of passenger indexes to reach the same strings. The stored quote is
     * deliberately left out: it is a large blob on every row, and scanning it to find
     * an airport code would cost the whole list its speed.
     *
     * @param  Builder<static>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $like = '%'.$term.'%';

        $query->where(fn (Builder $q) => $q
            ->whereAny(['reference', 'pnr', 'supplier_reference', 'booking_id', 'pax'], 'like', $like)
            ->orWhereHas('hotel', fn ($hotel) => $hotel->whereAny(['hotel_name', 'city'], 'like', $like)));
    }

    /**
     * The last few bookings this user made for one product — what the search pages
     * show above the form.
     *
     * Empty for a user who may not open a booking: every row of that panel is a link
     * into /bookings, so showing it to someone who would be refused there is a panel
     * of 403s. The agency scope sits on top of ownership for the same reason it does
     * on the list — redundant today, correct if visibility ever widens.
     *
     * @return EloquentCollection<int, static>
     */
    public static function recentFor(User $user, BookingProduct $product): EloquentCollection
    {
        if ($user->cannot('booking.view')) {
            return new EloquentCollection;
        }

        return static::visibleTo($user)
            ->where('user_id', $user->id)
            ->where('product', $product)
            // The property is what a hotel row is called; without this the panel runs
            // a query per row to read it.
            ->when($product === BookingProduct::Hotel, fn (Builder $query) => $query->with('hotel:id,booking_id,hotel_name'))
            ->latest()
            ->limit(self::RECENT)
            ->get();
    }

    /**
     * Who the booking is under.
     *
     * The lead guest on a hotel booking — the name the front desk asks for — and the
     * first passenger on a flight, where the air API has no such flag and the order
     * captured in the wizard is the only thing that says who is first.
     */
    public function leadPassengerName(): ?string
    {
        $pax = collect($this->pax ?? []);
        $lead = $pax->first(fn (array $p): bool => (bool) ($p['isLead'] ?? false)) ?? $pax->first() ?? [];

        $name = implode(' ', array_filter([
            $lead['title'] ?? null,
            $lead['firstName'] ?? null,
            $lead['lastName'] ?? null,
        ], 'filled'));

        return $name === '' ? null : $name;
    }

    /**
     * The one line that says what was bought, for a list that mixes products.
     *
     * A flight is its route and a hotel is its property; neither is on the shared
     * spine, so the caller would otherwise have to know which half to read.
     */
    public function itinerarySummary(): ?string
    {
        if ($this->isHotel()) {
            return $this->hotel?->hotel_name;
        }

        // One leg per trip: an agent scanning the list wants "MNL → CEB", not every
        // stop of a two-connection itinerary.
        $legs = collect(data_get($this->quote, 'trips', []))
            ->map(fn (array $trip) => collect($trip['segments'] ?? []))
            ->reject->isEmpty()
            ->map(fn ($segments) => [
                data_get($segments->first(), 'origin.code'),
                data_get($segments->last(), 'destination.code'),
            ]);

        $line = '';
        $arrivedAt = null;

        foreach ($legs as [$from, $to]) {
            // A return continues the line it came from — a round trip reads as
            // "MNL → CEB → MNL". An open jaw resumes elsewhere, so it starts its own.
            $line .= match (true) {
                $line === '' => "{$from} → {$to}",
                $arrivedAt === $from => " → {$to}",
                default => " · {$from} → {$to}",
            };
            $arrivedAt = $to;
        }

        return $line === '' ? null : $line;
    }

    /**
     * How this booking's price was arrived at, one row per pricing level, cheapest
     * rung first.
     *
     * Read these rather than recomputing from today's rules: each row carries a copy
     * of the rule that produced it, and the rule itself may since have changed.
     *
     * @return HasMany<BookingPriceLayer, $this>
     */
    public function priceLayers(): HasMany
    {
        return $this->hasMany(BookingPriceLayer::class)->orderBy('level');
    }

    /**
     * What the agency's own margin on this booking was — the rung it added itself.
     *
     * Zero for a booking made by the Main Office, which has no level above it to mark
     * up against, and for any booking taken before pricing existed.
     */
    public function agencyMargin(): string
    {
        // Parenthesised: `(string) $x ?? '0.00'` casts the null to '' before ?? ever
        // sees it, so the default never fires.
        return (string) ($this->priceLayers
            ->firstWhere('level', BookingPriceLayer::AGENCY)?->markup_amount ?? '0.00');
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
