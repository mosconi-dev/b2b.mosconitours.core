<?php

namespace App\Services\Pricing;

use App\Enums\BookingProduct;
use App\Enums\CalcType;
use App\Enums\PricingBasis;
use App\Enums\TierMode;
use App\Enums\TravelScope;
use App\Models\PricingRule;
use App\Services\Pricing\Calculators\CalculatorRegistry;

/**
 * A worked example of every calculation type, for the people configuring them.
 *
 * **The numbers are computed, not written.** Each example runs the REAL calculator out of
 * the registry against a sample rate, exactly as the engine would. Prose examples in an
 * admin screen rot the moment an arithmetic decision moves — and a help panel that
 * disagrees with what a booking is charged is worse than no help panel, for the same
 * reason the ladder preview posts to the engine rather than reimplementing it.
 *
 * Adding a calculation type therefore adds its own example: a sample here, a sentence on
 * CalcType::guidance(), and the screens pick both up.
 */
final class CalcTypeGuide
{
    /**
     * What each type is demonstrated with.
     *
     * The two percentages share a value on purpose. At 20% on the same rate a markup adds
     * 1,000 and a margin adds 1,250 — the difference between them is the single most
     * expensive misunderstanding available on this screen, and showing it costs nothing.
     *
     * `tiered` is the one shown against a bigger rate than the rest: at the shared 5,000
     * the fare never leaves the first band, and a tier table that demonstrates one band
     * demonstrates nothing.
     *
     * @var array<string, array{value: string, product: BookingProduct, pax: int, rooms: int, nights: int, params?: array<string, mixed>, net?: string}>
     */
    private const SAMPLES = [
        'fixed' => ['value' => '500', 'product' => BookingProduct::Flight, 'pax' => 1, 'rooms' => 1, 'nights' => 1],
        'percentage_markup' => ['value' => '20', 'product' => BookingProduct::Flight, 'pax' => 1, 'rooms' => 1, 'nights' => 1],
        'percentage_margin' => ['value' => '20', 'product' => BookingProduct::Flight, 'pax' => 1, 'rooms' => 1, 'nights' => 1],
        'per_pax' => ['value' => '350', 'product' => BookingProduct::Flight, 'pax' => 2, 'rooms' => 1, 'nights' => 1],
        'per_room_night' => ['value' => '200', 'product' => BookingProduct::Hotel, 'pax' => 2, 'rooms' => 2, 'nights' => 3],
        'tiered' => [
            'value' => '0', 'product' => BookingProduct::Flight, 'pax' => 1, 'rooms' => 1, 'nights' => 1,
            'net' => '30000',
            'params' => [
                'mode' => 'marginal',
                'bands' => [
                    ['up_to' => '10000', 'calc_type' => 'percentage_markup', 'value' => '12'],
                    ['up_to' => '50000', 'calc_type' => 'percentage_markup', 'value' => '8'],
                    ['up_to' => null, 'calc_type' => 'percentage_markup', 'value' => '5'],
                ],
            ],
        ],
        'none' => ['value' => '0', 'product' => BookingProduct::Flight, 'pax' => 1, 'rooms' => 1, 'nights' => 1],
    ];

    public function __construct(private readonly CalculatorRegistry $calculators) {}

    /**
     * One entry per type the form offers, in the order the form offers them.
     *
     * @return array<int, array{
     *     value: string, label: string, guidance: string, entered: ?string,
     *     working: string, adds: string, sells: string, net: string, restriction: ?string
     * }>
     */
    public function examples(): array
    {
        $net = Money::of(config('pricing.preview_net', 5000));

        return array_map(fn (CalcType $type): array => $this->example($type, $net), CalcType::implemented());
    }

    /**
     * @return array<string, mixed>
     */
    private function example(CalcType $type, Money $net): array
    {
        $sample = self::SAMPLES[$type->value] ?? self::SAMPLES['fixed'];
        $net = isset($sample['net']) ? Money::of($sample['net']) : $net;
        $rule = new PricingRule([
            'calc_type' => $type->value,
            'value' => $sample['value'],
            'params' => $sample['params'] ?? null,
            'basis' => PricingBasis::Net->value,
        ]);

        $context = new PricingContext(
            product: $sample['product'],
            supplier: $sample['product']->defaultSupplier(),
            scope: TravelScope::Domestic,
            net: NetPrice::of((string) $net),
            paxCount: $sample['pax'],
            roomCount: $sample['rooms'],
            nights: $sample['nights'],
        );

        // The real calculator, out of the real registry.
        $markup = $this->calculators->for($type)->compute($net, $rule, $context);

        return [
            'value' => $type->value,
            'label' => $type->label(),
            'guidance' => $type->guidance(),
            // Null for a type with nothing to enter, so the screen does not print
            // "Enter No markup" at somebody.
            'entered' => $this->entered($type, $sample),
            'working' => $this->working($type, $sample, $net, $markup),
            'adds' => $markup->formatted(),
            'sells' => $net->plus($markup)->formatted(),
            'net' => $net->formatted(),
            'restriction' => $type->productRestriction(),
        ];
    }

    /**
     * What somebody types to get this example — null for a type that asks for nothing, so
     * the screen does not print "Enter No markup" at anybody.
     *
     * A tiered rule asks for a table rather than a number, which is still something to
     * enter; it is the shape of it that goes here.
     *
     * @param  array<string, mixed>  $sample
     */
    private function entered(CalcType $type, array $sample): ?string
    {
        if ($type === CalcType::Tiered) {
            $table = TieredBands::fromParams($sample['params'] ?? null);

            return $table->summary().($table->mode() === TierMode::Marginal ? ', by slice' : ', on the whole fare');
        }

        return $type->usesValue() ? $type->describeAmount($sample['value']) : null;
    }

    /**
     * The arithmetic spelled out — "350.00 × 2 passengers".
     *
     * Presentation of one example rather than a property of the type, which is why it
     * lives here and not on the enum beside guidance().
     *
     * @param  array{value: string, product: BookingProduct, pax: int, rooms: int, nights: int}  $sample
     */
    private function working(CalcType $type, array $sample, Money $net, Money $markup): string
    {
        // describeAmount() already knows how to print a percentage without mangling it.
        $percent = $type->describeAmount($sample['value']);

        return match ($type) {
            CalcType::Fixed => "{$markup->formatted()}, flat — the fare does not enter into it",
            CalcType::PercentageMarkup => "{$percent} × {$net->formatted()}, the supplier's rate",
            CalcType::PercentageMargin => "{$percent} of the {$net->plus($markup)->formatted()} it sells for",
            CalcType::PerPax => "{$sample['value']} × {$sample['pax']} passengers",
            CalcType::PerRoomNight => "{$sample['value']} × {$sample['rooms']} rooms × {$sample['nights']} nights",
            CalcType::Tiered => $this->tieredWorking(TieredBands::fromParams($sample['params'] ?? null), $net),
            default => 'nothing, deliberately',
        };
    }

    /**
     * "12% of 10,000.00 + 8% of 20,000.00" — the split the table actually made, taken
     * from TieredBands rather than reconstructed, so the working cannot drift from the
     * number beside it.
     */
    private function tieredWorking(TieredBands $table, Money $net): string
    {
        if ($table->mode() === TierMode::Whole) {
            return $table->forAmount($net)->amountLabel()." of the whole {$net->formatted()}";
        }

        return implode(' + ', array_map(
            fn (array $slice): string => $slice['band']->amountLabel().' of '.$slice['amount']->formatted(),
            $table->slices($net),
        ));
    }
}
