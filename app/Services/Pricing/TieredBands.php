<?php

namespace App\Services\Pricing;

use App\Enums\CalcType;
use App\Enums\TierMode;
use App\Enums\TierUnit;
use App\Services\Pricing\Exceptions\PricingException;

/**
 * A tier table: charge one way up to an amount, another way above it.
 *
 * 12% under ₱10,000, 8% to ₱50,000, 5% above. It protects the margin on cheap fares
 * without pricing long-haul out of the market, and it is the single most requested
 * pricing shape in travel retail.
 *
 * **The cliff.** Every boundary in a table like that is a step, and a careless step runs
 * backwards. 12% of ₱10,000 is ₱1,200; 8% of ₱10,001 is ₱800. A fare one peso more
 * expensive sells ₱399 CHEAPER, and nobody finds out until a margin report looks wrong
 * months later. `inversions()` computes the markup on both sides of every boundary and
 * names the ones that fall; StorePricingRuleRequest refuses a table that has any.
 *
 * The bands live in `pricing_rules.params`, are copied whole into the booking's rule
 * snapshot, and are read here by both the calculator and the form that writes them, so
 * a table means the same thing to the engine, to the validator and to the audit trail.
 */
final class TieredBands
{
    /** @param array<int, TieredBand> $bands ascending, the last one open-ended */
    private function __construct(
        private readonly array $bands,
        private readonly TierMode $mode,
        private readonly TierUnit $unit,
    ) {}

    /**
     * Build from a rule's `params`. Assumes it is usable — call problems() first.
     *
     * @throws PricingException
     */
    public static function fromParams(mixed $params): self
    {
        $problems = self::problems($params);

        if ($problems !== []) {
            throw new PricingException('This tier table cannot be used: '.$problems[0]);
        }

        return self::fromRows(self::rows($params), self::modeOf($params), self::unitOf($params));
    }

    /**
     * Everything wrong with a raw table, in plain words, ordered so the first one is the
     * one worth showing. Empty means it is usable.
     *
     * Structure first, then arithmetic: markupOn() throws on a margin of 100%, so the
     * inversion check only runs once every band is known to compute.
     *
     * @return array<int, string>
     */
    public static function problems(mixed $params): array
    {
        $mode = is_array($params) ? ($params['mode'] ?? null) : null;

        if (! self::blank($mode) && (! is_string($mode) || TierMode::tryFrom($mode) === null)) {
            return ['a tier table is charged either on the whole fare or by slice, and this one says neither.'];
        }

        $unit = is_array($params) ? ($params['bands_on'] ?? null) : null;

        if (! self::blank($unit) && (! is_string($unit) || TierUnit::tryFrom($unit) === null)) {
            return ['a tier table reads either the whole booking or one unit of it, and this one says neither.'];
        }

        $rows = self::rows($params);

        if ($rows === []) {
            return ['a tiered rule needs a band table, and this one has no bands.'];
        }

        if (count($rows) < 2) {
            return ['a table with one band is just that band — use its type directly instead.'];
        }

        $problems = self::structuralProblems($rows);

        return $problems === []
            ? self::fromRows($rows, self::modeOf($params), self::unitOf($params))->inversionProblems()
            : $problems;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private static function structuralProblems(array $rows): array
    {
        $problems = [];
        $allowed = array_map(fn (CalcType $type): string => $type->value, TieredBand::allowedTypes());
        $last = count($rows) - 1;
        $previous = null;

        foreach ($rows as $index => $row) {
            $position = $index + 1;
            $type = CalcType::tryFrom((string) ($row['calc_type'] ?? ''));
            $value = $row['value'] ?? null;
            $upTo = $row['up_to'] ?? null;

            if ($type === null || ! in_array($type->value, $allowed, true)) {
                $problems[] = "band {$position} is charged in a way a band cannot be. Use one of: "
                    .implode(', ', array_map(
                        fn (CalcType $t): string => $t->label(),
                        TieredBand::allowedTypes(),
                    )).'.';

                continue;
            }

            if (! is_numeric($value) || (float) $value < 0) {
                $problems[] = "band {$position} needs an amount of zero or more.";
            } elseif ($type === CalcType::PercentageMargin && (float) $value >= 100) {
                $problems[] = "band {$position}'s margin of {$value}% cannot be reached — a margin is a "
                    .'share of the selling price, so it must be under 100%.';
            }

            // Exactly one open end, at the top. Without it a fare above the last band
            // has no band at all, and the engine would have to invent an answer.
            if (self::blank($upTo)) {
                if ($index !== $last) {
                    $problems[] = "band {$position} has no upper limit, so no band below it can ever be "
                        .'reached. Only the last band is open-ended.';
                }

                continue;
            }

            if ($index === $last) {
                $problems[] = 'the last band needs an empty upper limit — it is what prices every fare '
                    .'above the table.';

                continue;
            }

            if (! is_numeric($upTo) || (float) $upTo <= 0) {
                $problems[] = "band {$position} needs an upper limit above zero.";

                continue;
            }

            if ($previous !== null && (float) $upTo <= $previous) {
                $problems[] = 'the upper limits have to climb: band '.$position.' stops at '
                    .number_format((float) $upTo, 2).', which is not above the '
                    .number_format($previous, 2).' before it.';
            }

            $previous = (float) $upTo;
        }

        return $problems;
    }

    /**
     * What this table adds to a basis amount — the whole of a tiered rule's arithmetic.
     *
     * In `whole` mode one band prices the entire fare. In `marginal` mode every band
     * prices its own slice and they are summed, which is why marginal can never invert.
     */
    public function markupOn(Money $basis, int $units = 1): Money
    {
        // One unit is priced, then multiplied back up: the bands read a ticket rather
        // than a booking, and a flat band means an amount per ticket. `units` is a
        // positive constant, so it scales both sides of every boundary equally and
        // leaves the inversion check below exactly as valid as it was.
        $each = $units > 1 ? $basis->dividedBy($units) : $basis;

        $markup = $this->mode === TierMode::Whole
            ? $this->forAmount($each)->markupOn($each)
            : $this->sumOfSlices($each);

        return $units > 1 ? $markup->times($units) : $markup;
    }

    private function sumOfSlices(Money $basis): Money
    {
        $markup = Money::zero();

        foreach ($this->slices($basis) as $slice) {
            $markup = $markup->plus($slice['band']->markupOn($slice['amount']));
        }

        return $markup;
    }

    /**
     * How a fare divides across the bands, for marginal pricing — and for anything that
     * has to show its working, which is why the split is exposed rather than inlined.
     *
     * Bands the fare never reaches are absent, so a flat band above the fare contributes
     * nothing.
     *
     * @return array<int, array{band: TieredBand, amount: Money}>
     */
    public function slices(Money $basis): array
    {
        $slices = [];
        $floor = Money::zero();

        foreach ($this->bands as $band) {
            // The part of the fare inside this band: nothing above the fare, nothing
            // below the band.
            $ceiling = $band->upTo === null || $basis->lessThan($band->upTo) ? $basis : $band->upTo;
            $amount = $ceiling->minus($floor);

            if ($amount->isNegative() || $amount->isZero()) {
                break;
            }

            $slices[] = ['band' => $band, 'amount' => $amount];
            $floor = $ceiling;
        }

        return $slices;
    }

    /**
     * Boundaries where the table pays LESS on a more expensive fare. See the class note.
     *
     * @return array<int, array{at: Money, below: Money, above: Money}>
     */
    public function inversions(): array
    {
        // Marginal bands cannot invert: every extra peso of fare adds a non-negative
        // amount, so the markup only ever climbs. Nothing to check.
        if ($this->mode === TierMode::Marginal) {
            return [];
        }

        $found = [];
        $bands = array_values($this->bands);

        foreach ($bands as $index => $band) {
            $next = $bands[$index + 1] ?? null;

            if ($next === null || $band->upTo === null) {
                continue;
            }

            $below = $band->markupOn($band->upTo);
            $above = $next->markupOn($band->upTo);

            if ($above->lessThan($below)) {
                $found[] = ['at' => $band->upTo, 'below' => $below, 'above' => $above];
            }
        }

        return $found;
    }

    /**
     * The band that prices this amount. The last band is open-ended, so there is always
     * one — see structuralProblems(), which is what guarantees it.
     */
    public function forAmount(Money $basis): TieredBand
    {
        return $this->bands[$this->indexFor($basis)];
    }

    /**
     * The table as a rate sheet: one row per band with BOTH bounds spelled out.
     *
     * A band stores only its upper limit, because storing the lower one too is storing
     * the same boundary twice and inviting the two to disagree. A screen showing the
     * table still wants both columns, so they are derived here rather than in a view.
     *
     * `from` on the second band is a centavo above the first band's limit — the smallest
     * fare that is genuinely in it, and the honest version of a rate sheet's "10,001".
     *
     * @return array<int, array{tier: int, from: Money, to: ?Money, charge: string}>
     */
    public function grid(): array
    {
        $rows = [];
        $previous = null;

        foreach ($this->bands as $index => $band) {
            $rows[] = [
                'tier' => $index + 1,
                'from' => $previous === null ? Money::zero() : $previous->plus(Money::of('0.01')),
                'to' => $band->upTo,
                'charge' => $band->amountLabel(),
            ];

            $previous = $band->upTo;
        }

        return $rows;
    }

    /** @return array<int, TieredBand> */
    public function all(): array
    {
        return $this->bands;
    }

    public function count(): int
    {
        return count($this->bands);
    }

    /** "12% / 8% / 5%" — the shape of the table at a glance, for the rule list. */
    public function summary(): string
    {
        return implode(' / ', array_map(fn (TieredBand $band): string => $band->amountLabel(), $this->bands));
    }

    /** "up to 10,000.00: 12%, 10,000.00–50,000.00: 8%, above 50,000.00: 5%" */
    public function describe(): string
    {
        $described = [];

        foreach ($this->bands as $index => $band) {
            $described[] = $this->rangeLabelFor($index).': '.$band->amountLabel();
        }

        return implode(', ', $described);
    }

    /**
     * The whole table in a few words, for a rules list.
     *
     * Falls back to the type's own name when the table cannot be read: a label is not
     * the place to discover a broken rule, and the form is what refuses one.
     */
    public static function label(mixed $params): string
    {
        try {
            $table = self::fromParams($params);
            $unit = $table->unit->shortLabel();

            return 'Tiered: '.$table->summary().($unit === null ? '' : ", {$unit}");
        } catch (PricingException) {
            return CalcType::Tiered->label();
        }
    }

    /**
     * The band that actually fired, for a rung that has already been priced — "8%
     * (10,000.00–50,000.00)". What makes a tiered price explain itself in the ladder
     * preview and in a booking's stored layers.
     */
    public static function labelFor(mixed $params, Money $basis): string
    {
        try {
            $table = self::fromParams($params);

            // A per-unit table was read at a fare this rung does not record — a layer
            // keeps the booking's basis, not its head count — so naming one band would be
            // a guess. Marginal pricing used every band up to the fare, so naming one
            // would be a lie. Either way the table and how it was read is what can be
            // said truthfully.
            if ($table->unit !== TierUnit::Booking) {
                return $table->summary().', '.$table->unit->shortLabel();
            }

            if ($table->mode === TierMode::Marginal) {
                return $table->summary().', by slice';
            }

            $index = $table->indexFor($basis);

            return $table->bands[$index]->amountLabel().' ('.$table->rangeLabelFor($index).')';
        } catch (PricingException) {
            return CalcType::Tiered->label();
        }
    }

    private function rangeLabelFor(int $index): string
    {
        return $this->bands[$index]->rangeLabel($index === 0 ? null : $this->bands[$index - 1]->upTo);
    }

    private function indexFor(Money $basis): int
    {
        foreach ($this->bands as $index => $band) {
            if ($band->covers($basis)) {
                return $index;
            }
        }

        throw new PricingException(
            "No tier band covers {$basis}. The table's last band should have no upper limit."
        );
    }

    /** @return array<int, string> */
    private function inversionProblems(): array
    {
        return array_map(
            fn (array $inversion): string => 'a fare just above '
                .number_format($inversion['at']->toFloat(), 2).' would be charged '
                .$inversion['above']->formatted().' where one at '
                .number_format($inversion['at']->toFloat(), 2).' is charged '
                .$inversion['below']->formatted().', so the more expensive fare sells for less. '
                .'Raise the band above, move the boundary, or charge the bands by slice — where each '
                .'rate applies only to its own part of the fare, rates that fall cannot do this.',
            $this->inversions(),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function fromRows(array $rows, TierMode $mode, TierUnit $unit): self
    {
        $bands = [];

        foreach ($rows as $row) {
            $bands[] = new TieredBand(
                upTo: self::blank($row['up_to'] ?? null) ? null : Money::of((string) $row['up_to']),
                calcType: CalcType::from((string) $row['calc_type']),
                value: (string) $row['value'],
            );
        }

        return new self($bands, $mode, $unit);
    }

    /**
     * The mode a table declares, defaulting rather than failing: a table written before
     * there were two modes means the plain reading of its own words.
     */
    public static function modeOf(mixed $params): TierMode
    {
        $mode = is_array($params) ? ($params['mode'] ?? null) : null;

        return (is_string($mode) ? TierMode::tryFrom($mode) : null) ?? TierMode::default();
    }

    public function mode(): TierMode
    {
        return $this->mode;
    }

    /**
     * What a table declares it measures, defaulting to the whole booking — the reading a
     * table written before there was a choice was made under.
     */
    public static function unitOf(mixed $params): TierUnit
    {
        $unit = is_array($params) ? ($params['bands_on'] ?? null) : null;

        return (is_string($unit) ? TierUnit::tryFrom($unit) : null) ?? TierUnit::Booking;
    }

    public function unit(): TierUnit
    {
        return $this->unit;
    }

    /**
     * The band rows out of whatever the form or the column handed over.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function rows(mixed $params): array
    {
        $bands = is_array($params) ? ($params['bands'] ?? null) : null;

        if (! is_array($bands)) {
            return [];
        }

        return array_values(array_filter($bands, is_array(...)));
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
