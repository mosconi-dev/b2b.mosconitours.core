<?php

namespace App\Models;

use App\Enums\BookingProduct;
use App\Enums\BookingStatus;
use App\Enums\Supplier;
use App\Models\Concerns\BelongsToAgency;
use App\Services\Pricing\FareAllocation;
use App\Services\Pricing\Money;
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
     * Never serialized.
     *
     * The engine uses all three internally and the Main Office reads them through its
     * own guarded screens, but a booking that reaches `response()->json()` — today or
     * in a controller nobody has written yet — must not carry our cost or anyone's
     * margin. Dropping them here makes that structural rather than something every
     * future endpoint has to remember.
     *
     * @var list<string>
     */
    protected $hidden = ['net_amount', 'cost_amount', 'markup_total', 'quote_raw'];

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
    /**
     * The fare breakdown as this viewer is allowed to see it.
     *
     * The stored `quote` is the supplier's own snapshot, so its `baseFare` and `tax`
     * rows sum to the NET. Printed beside the selling total — which is on the same page
     * and on the e-ticket — they hand an agency our cost in one addition, and the Main
     * Office's markup in one subtraction after that. So the rows are reallocated to the
     * SELLING price and the supplier's components come out for anyone not entitled to
     * them.
     *
     * The denominator is what the rows themselves sum to rather than `net_amount`,
     * because `net_amount` also carries ancillaries and the rows do not. The target is
     * the selling total less ancillaries, so the add-ons stay their own line and the
     * document still reconciles.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * What the add-ons SOLD for — `ancillary_total` is what they cost us.
     *
     * BookingService folds ancillaries into net before pricing, so the agency was
     * charged markup on its bags and meals. The documents printed the supplier's figure
     * instead: the add-on lines showed our cost, and the per-passenger fares silently
     * absorbed the add-on markup to make the total still reconcile.
     *
     * Attributed from the STORED layers, not by re-pricing: a document must show the
     * rules that applied when the booking was made, and re-running the engine would
     * show today's. Each layer contributes the share of its markup that the ancillary
     * part of its basis earned — which is exact for a percentage, and zero for a flat
     * rule, since a ₱250 ticketing fee is charged once for the booking rather than once
     * per meal. That mirrors how the wizard prices the add-on menu.
     *
     * The fare portion is then defined as the REMAINDER (see `fareLinesFor()`), so the
     * document reconciles to `total_amount` exactly however the attribution falls.
     */
    public function addOnSellTotal(): Money
    {
        $ancillaries = Money::of($this->ancillary_total ?? 0);

        if ($ancillaries->isZero()) {
            return Money::zero();
        }

        $sell = $ancillaries;

        foreach ($this->priceLayers as $layer) {
            $basis = Money::of($layer->basis_amount ?? 0);
            $snapshot = (array) ($layer->rule_snapshot ?? []);

            // Flat money does not scale with the basis, so none of it belongs to the
            // add-ons; and a rule priced on the fare alone never saw them.
            $isFlat = in_array($snapshot['calc_type'] ?? '', ['fixed', 'per_pax', 'per_room_night', 'none'], true);
            $sawAncillaries = ($snapshot['applies_to'] ?? 'total') === 'total';

            if ($isFlat || ! $sawAncillaries || $basis->isZero()) {
                continue;
            }

            $sell = $sell->plus(
                Money::of($layer->markup_amount ?? 0)->times(bcdiv((string) $ancillaries, (string) $basis, 6))
            );
        }

        return $sell;
    }

    public function fareLinesFor(?User $viewer): array
    {
        $rows = (array) data_get($this->quote, 'fareBreakdown', []);

        if ($rows === []) {
            return [];
        }

        $rows = FareAllocation::allocate(
            $rows,
            FareAllocation::netOf($rows),
            // The SELLING price of the add-ons comes off, not their cost. Subtracting
            // `ancillary_total` here left the add-on markup inside the fare rows, so
            // each passenger's fare was overstated by a share of someone's baggage.
            Money::of($this->total_amount ?? 0)->minus($this->addOnSellTotal()),
        );

        if ($viewer === null || ! $viewer->isPlatformStaff()) {
            $rows = FareAllocation::redact($rows);
        }

        return array_map(function (array $row): array {
            $count = max((int) ($row['count'] ?? 1), 1);
            $total = (float) ($row['amountTotal'] ?? 0);

            return $row + [
                'label' => $row['passengerType'] ?? 'Passenger',
                'count' => $count,
                'total' => $total,
                'each' => $total / $count,
            ];
        }, $rows);
    }

    /**
     * The SUM of the agency's rungs, not the first of them.
     *
     * A level contributes one rung per matching rule, so an agency running a base rate
     * and a service fee has two. Reading only the first would under-report what it
     * earned, on the screen that exists to tell it what it earned.
     */
    public function agencyMargin(): string
    {
        return $this->priceLayers
            ->where('level', BookingPriceLayer::AGENCY)
            ->reduce(
                fn (string $carry, BookingPriceLayer $l): string => bcadd($carry, (string) $l->markup_amount, 2),
                '0.00',
            );
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
