<?php

namespace App\Models;

use App\Enums\CalcType;
use App\Enums\PricingBasis;
use App\Enums\TravelScope;
use App\Services\Pricing\PricingContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rule inside a strategy: what it matches, and what it adds.
 *
 * Rules within a strategy are CUMULATIVE — every rule that matches contributes, and the
 * contributions sum. A base rate, a service fee and a surcharge are three rules, and a
 * booking they all match pays all three. Each works from the supplier net, so they never
 * compound on one another and the order they run in cannot change the total.
 *
 * `description` is the optional note explaining why the rule was added, and it travels
 * into the snapshot so a past price can still account for itself after the rule is gone.
 */
#[Fillable([
    'pricing_strategy_id', 'description', 'product', 'supplier', 'scope', 'matchers',
    'calc_type', 'value', 'basis', 'applies_to',
    'min_markup', 'max_markup', 'rounding', 'priority',
    'valid_from', 'valid_to', 'is_active',
])]
class PricingRule extends Model
{
    use HasFactory;

    /** `product` and `supplier` wildcard. */
    public const ANY = '*';

    protected function casts(): array
    {
        return [
            'matchers' => 'array',
            'calc_type' => CalcType::class,
            'basis' => PricingBasis::class,
            'value' => 'decimal:4',
            'min_markup' => 'decimal:2',
            'max_markup' => 'decimal:2',
            'priority' => 'integer',
            'version' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Every edit bumps `version`, so a quote can tell whether the rule moved under it
     * between search and booking. Done in the model rather than the service because a
     * rule edited from anywhere at all needs it.
     */
    protected static function booted(): void
    {
        static::updating(function (PricingRule $rule): void {
            // Guard against recursion and against a no-op save bumping the version.
            if ($rule->isDirty() && ! $rule->isDirty('version')) {
                $rule->version = (int) $rule->version + 1;
            }
        });
    }

    /**
     * @return BelongsTo<PricingStrategy, $this>
     */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(PricingStrategy::class, 'pricing_strategy_id');
    }

    /**
     * Whether this rule applies to what is being priced.
     *
     * Coarse tests first — they reject the most rules for the least work — then the
     * JSON matchers, which are the only part that needs decoding.
     */
    public function matches(PricingContext $context): bool
    {
        return $this->matchesProduct($context)
            && $this->matchesSupplier($context)
            && $this->matchesScope($context)
            && $this->matchesDates($context)
            && $this->matchesAttributes($context);
    }

    private function matchesProduct(PricingContext $context): bool
    {
        return $this->product === self::ANY || $this->product === $context->product->value;
    }

    private function matchesSupplier(PricingContext $context): bool
    {
        return blank($this->supplier)
            || $this->supplier === self::ANY
            || $this->supplier === $context->supplier->value;
    }

    private function matchesScope(PricingContext $context): bool
    {
        return $this->scope === 'any' || $this->scope === $context->scope->value;
    }

    /**
     * The window is measured against the TRAVEL date when the rule has one and the
     * context knows it — a July peak-season rule is about when the guest travels, not
     * when the agent happened to book. Without a travel date it falls back to the
     * booking date, which is the only other thing there is.
     */
    private function matchesDates(PricingContext $context): bool
    {
        if ($this->valid_from === null && $this->valid_to === null) {
            return true;
        }

        $against = $context->travelDate ?? $context->bookedOn();

        if ($this->valid_from !== null && $against->lt($this->valid_from->startOfDay())) {
            return false;
        }

        return $this->valid_to === null || $against->lte($this->valid_to->endOfDay());
    }

    /**
     * Each key in `matchers` must be satisfied by the context.
     *
     * A list value is an "any of" — `{"airline": ["PR", "5J"]}`. A scalar is equality.
     * Booleans compare loosely because the JSON round-trip turns them into true/false
     * while a DTO may hand over 1/0.
     */
    private function matchesAttributes(PricingContext $context): bool
    {
        foreach ((array) ($this->matchers ?? []) as $key => $expected) {
            $actual = $context->attribute($key);

            if (is_array($expected)) {
                if (! in_array($actual, $expected, false)) {
                    return false;
                }

                continue;
            }

            if (is_bool($expected)) {
                if ((bool) $actual !== $expected) {
                    return false;
                }

                continue;
            }

            if ($actual === null || (string) $actual !== (string) $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * What gets copied onto a booking's price layer.
     *
     * A copy, not a join: this rule is editable data and the booking must still explain
     * itself after it has been changed twice or deleted.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'rule_id' => $this->id,
            'version' => (int) $this->version,
            'description' => $this->description,
            'product' => $this->product,
            'supplier' => $this->supplier,
            'scope' => $this->scope,
            'matchers' => $this->matchers,
            'calc_type' => $this->calc_type->value,
            'value' => (string) $this->value,
            'basis' => $this->basis->value,
            'applies_to' => $this->applies_to,
            'min_markup' => $this->min_markup,
            'max_markup' => $this->max_markup,
            'rounding' => $this->rounding,
            'priority' => (int) $this->priority,
        ];
    }

    /**
     * One line describing the rule, for the admin list and the ladder preview.
     */
    public function summary(): string
    {
        $amount = $this->amountLabel();

        $product = $this->product === self::ANY ? 'all products' : $this->product;
        $scope = $this->scope === 'any' ? '' : ' '.TravelScope::from($this->scope)->label();

        return trim("{$amount} on {$product}{$scope}");
    }

    /**
     * What the rule adds, as a phrase — "10%", "500.00 per passenger", "No markup".
     *
     * The unit is part of the amount, not decoration: a flat 500 and a 500 per passenger
     * are the same three characters otherwise, and the second one costs a family of five
     * five times the first.
     */
    public function amountLabel(): string
    {
        if (! $this->calc_type->usesValue()) {
            return $this->calc_type->label();
        }

        $amount = $this->calc_type->isPercentage()
            ? rtrim(rtrim((string) $this->value, '0'), '.').'%'
            : number_format((float) $this->value, 2);

        $unit = $this->calc_type->unitLabel();

        return $unit === null ? $amount : "{$amount} {$unit}";
    }
}
