<?php

namespace Tests\Feature\Pricing;

use App\Enums\BookingProduct;
use App\Enums\CalcType;
use App\Enums\Supplier;
use App\Enums\TravelScope;
use App\Models\Agency;
use App\Models\PricingRule;
use App\Models\PricingStrategy;
use App\Services\Pricing\CalcTypeGuide;
use App\Services\Pricing\Calculators\CalculatorRegistry;
use App\Services\Pricing\NetPrice;
use App\Services\Pricing\PricingContext;
use App\Services\Pricing\PricingEngine;
use App\Services\Pricing\StrategyResolver;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each calculation type, through the real engine.
 *
 * Not against the Calculator classes directly: what matters is the figure a booking is
 * charged, and that comes out of the engine after the basis is resolved, the floor and
 * cap are applied and the total is rounded. A calculator tested in isolation can be
 * right about arithmetic the engine never asks it for.
 */
class CalculatorTypesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $mainOffice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mainOffice = Agency::factory()->create(['name' => 'Main Office']);

        app(Settings::class)->set(StrategyResolver::MAIN_OFFICE_SETTING, (string) $this->mainOffice->id);
    }

    private function strategy(): PricingStrategy
    {
        return PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id]);
    }

    private function flight(float $net = 5000, int $pax = 1): PricingContext
    {
        return new PricingContext(
            product: BookingProduct::Flight,
            supplier: Supplier::TboAir,
            scope: TravelScope::Domestic,
            net: NetPrice::of($net),
            paxCount: $pax,
        );
    }

    private function hotel(float $net = 5000, int $rooms = 1, int $nights = 1): PricingContext
    {
        return new PricingContext(
            product: BookingProduct::Hotel,
            supplier: Supplier::TboHotel,
            scope: TravelScope::Domestic,
            net: NetPrice::of($net),
            roomCount: $rooms,
            nights: $nights,
        );
    }

    private function sell(PricingContext $context): string
    {
        return (string) app(PricingEngine::class)->quote($context, $this->mainOffice)->sell->amount;
    }

    // ------------------------------------------------------------- the registry ----

    public function test_every_implemented_type_has_a_calculator_behind_it(): void
    {
        $registry = app(CalculatorRegistry::class);

        foreach (CalcType::implemented() as $type) {
            $this->assertTrue($registry->has($type), "{$type->value} is offered by the form with no calculator");
        }
    }

    public function test_tiered_is_offered_now_that_params_can_hold_its_bands(): void
    {
        // It carries a table rather than one number, which is what `params` is for.
        $this->assertContains(CalcType::Tiered, CalcType::implemented());
        $this->assertArrayHasKey('tiered', CalcType::options());
    }

    // ------------------------------------------------------- the product gate ----

    public function test_a_per_unit_type_is_offered_only_on_the_product_it_scales_with(): void
    {
        $this->assertArrayHasKey('per_pax', CalcType::optionsForProduct('flight'));
        $this->assertArrayNotHasKey('per_pax', CalcType::optionsForProduct('hotel'));

        $this->assertArrayHasKey('per_room_night', CalcType::optionsForProduct('hotel'));
        $this->assertArrayNotHasKey('per_room_night', CalcType::optionsForProduct('flight'));
    }

    public function test_a_rule_matching_every_product_gets_neither_per_unit_type(): void
    {
        // Neither means the same thing on both sides of the wildcard, and a
        // per-passenger fee on a rule that also matches hotels is the live system's bug
        // with a wider blast radius.
        $any = CalcType::optionsForProduct('*');

        $this->assertArrayNotHasKey('per_pax', $any);
        $this->assertArrayNotHasKey('per_room_night', $any);
        $this->assertArrayHasKey('percentage_markup', $any, 'a percentage still means one thing everywhere');
    }

    public function test_the_types_that_mean_one_thing_everywhere_are_offered_on_every_product(): void
    {
        foreach (CalcType::optionsByProduct() as $product => $options) {
            foreach (['fixed', 'percentage_markup', 'percentage_margin', 'none'] as $universal) {
                $this->assertArrayHasKey($universal, $options, "{$universal} is missing on {$product}");
            }
        }
    }

    // ------------------------------------------------------- percentage margin ----

    public function test_a_margin_takes_its_share_of_the_selling_price(): void
    {
        PricingRule::factory()->margin(20)->create(['pricing_strategy_id' => $this->strategy()->id]);

        // 20% OF THE SELL: 5,000 + 1,250 = 6,250, and 1,250 is 20% of 6,250.
        $this->assertSame('6250.00', $this->sell($this->flight(5000)));
    }

    public function test_a_margin_and_a_markup_at_the_same_figure_do_not_agree(): void
    {
        PricingRule::factory()->percentage(20)->create(['pricing_strategy_id' => $this->strategy()->id]);

        // The 250 a booking that makes these two types rather than one with a flag.
        $this->assertSame('6000.00', $this->sell($this->flight(5000)));
    }

    // --------------------------------------------------------------- per pax ----

    public function test_a_per_passenger_fee_scales_on_head_count(): void
    {
        PricingRule::factory()->perPax(350)->create(['pricing_strategy_id' => $this->strategy()->id]);

        $this->assertSame('5350.00', $this->sell($this->flight(5000, pax: 1)));
        $this->assertSame('6750.00', $this->sell($this->flight(5000, pax: 5)), 'a family of five pays five');
    }

    // -------------------------------------------------------- per room-night ----

    public function test_a_per_room_night_fee_scales_on_rooms_and_nights(): void
    {
        PricingRule::factory()->perRoomNight(200)->create(['pricing_strategy_id' => $this->strategy()->id]);

        $this->assertSame('5200.00', $this->sell($this->hotel(5000, rooms: 1, nights: 1)));
        $this->assertSame('6200.00', $this->sell($this->hotel(5000, rooms: 2, nights: 3)), 'six room-nights');
    }

    public function test_a_per_room_night_fee_ignores_head_count(): void
    {
        PricingRule::factory()->perRoomNight(200)->create(['pricing_strategy_id' => $this->strategy()->id]);

        // The live system's bug: two adults in one double room paid one room rate and
        // two markups. Head count is not an axis a hotel rate moves on.
        $twoAdultsOneRoom = new PricingContext(
            product: BookingProduct::Hotel,
            supplier: Supplier::TboHotel,
            scope: TravelScope::Domestic,
            net: NetPrice::of(5000),
            paxCount: 2,
            roomCount: 1,
            nights: 1,
        );

        $this->assertSame('5200.00', $this->sell($twoAdultsOneRoom));
    }

    // ------------------------------------------------------------------ none ----

    public function test_an_explicit_zero_contributes_a_rung_worth_nothing(): void
    {
        PricingRule::factory()->none()->create([
            'pricing_strategy_id' => $this->strategy()->id,
            'description' => 'Negotiated corporate rate — pass through at cost',
        ]);

        $price = app(PricingEngine::class)->quote($this->flight(5000), $this->mainOffice);

        $this->assertSame('5000.00', (string) $price->sell->amount);

        // A rung, not the absence of one: the breakdown must say a rule decided this,
        // because "nobody has configured anything" looks identical otherwise.
        $this->assertCount(1, $price->layers);
        $this->assertSame('0.00', (string) $price->layers[0]->markup);
    }

    // ----------------------------------------------------- through the engine ----

    public function test_a_floor_lifts_a_per_passenger_fee_like_any_other(): void
    {
        PricingRule::factory()->perPax(100)->create([
            'pricing_strategy_id' => $this->strategy()->id,
            'min_markup' => '500',
        ]);

        // 1 pax x 100 = 100, floored to 500. The clamp is the engine's, not the type's.
        $this->assertSame('5500.00', $this->sell($this->flight(5000, pax: 1)));
    }

    public function test_the_new_types_sum_with_the_old_ones_in_one_level(): void
    {
        $strategy = $this->strategy();

        PricingRule::factory()->margin(20)->create(['pricing_strategy_id' => $strategy->id]);
        PricingRule::factory()->perPax(350)->create(['pricing_strategy_id' => $strategy->id]);
        PricingRule::factory()->fixed(150)->create(['pricing_strategy_id' => $strategy->id]);

        // 5,000 + 1,250 + (350 x 2) + 150. Every match contributes; none compounds.
        $this->assertSame('7100.00', $this->sell($this->flight(5000, pax: 2)));
    }

    // ------------------------------------------------------- the worked guide ----

    public function test_every_offered_type_is_demonstrated_by_the_real_calculator(): void
    {
        $examples = collect(app(CalcTypeGuide::class)->examples())->keyBy('value');

        $this->assertSame(
            array_column(array_map(fn (CalcType $t): array => ['v' => $t->value], CalcType::implemented()), 'v'),
            $examples->keys()->all(),
            'the guide covers exactly the types the form offers, in the same order',
        );

        foreach ($examples as $value => $example) {
            $this->assertNotSame('', $example['guidance'], "{$value} has no explanation");
            $this->assertNotSame('', $example['working'], "{$value} has no arithmetic");
        }

        // The one type with nothing to type in says so, rather than asking for "No markup".
        $this->assertNull($examples['none']['entered']);
        $this->assertSame('5,000.00', $examples['none']['sells']);
    }

    public function test_each_type_has_its_own_sample_rather_than_borrowing_one(): void
    {
        // example() falls back to the fixed sample so a new type can never blank the
        // screen — but a fallback that silently demonstrates the WRONG arithmetic is the
        // whole failure this guide exists to prevent, so the coverage is pinned here.
        $examples = collect(app(CalcTypeGuide::class)->examples())->keyBy('value');

        $this->assertSame('350.00 per passenger', $examples['per_pax']['entered']);
        $this->assertSame('200.00 per room-night', $examples['per_room_night']['entered']);
        $this->assertStringContainsString('2 passengers', $examples['per_pax']['working']);
        $this->assertStringContainsString('2 rooms', $examples['per_room_night']['working']);
        $this->assertStringContainsString('3 nights', $examples['per_room_night']['working']);
    }

    public function test_the_guide_shows_markup_and_margin_diverging_at_the_same_number(): void
    {
        // The point of demonstrating both at 20%: one adds 1,000 and the other 1,250 on
        // the same rate, which is the most expensive misreading available on the screen.
        $examples = collect(app(CalcTypeGuide::class)->examples())->keyBy('value');

        $this->assertSame('20%', $examples['percentage_markup']['entered']);
        $this->assertSame('20%', $examples['percentage_margin']['entered']);

        $this->assertSame('1,000.00', $examples['percentage_markup']['adds']);
        $this->assertSame('1,250.00', $examples['percentage_margin']['adds']);
        $this->assertSame('6,250.00', $examples['percentage_margin']['sells']);
    }

    public function test_a_whole_percentage_is_not_mangled_by_trimming_its_zeros(): void
    {
        // "20" trimmed of trailing zeros is "2". Invisible while the only caller was an
        // Eloquent decimal:4 attribute, which always carries a point.
        $markup = CalcType::PercentageMarkup;

        $this->assertSame('20%', $markup->describeAmount('20'));
        $this->assertSame('20%', $markup->describeAmount(20));
        $this->assertSame('20%', $markup->describeAmount('20.0000'));
        $this->assertSame('7.5%', $markup->describeAmount('7.5000'));
        $this->assertSame('100%', $markup->describeAmount('100'));
        $this->assertSame('0%', $markup->describeAmount('0.0000'));
    }

    // ---------------------------------------------------------- what it reads ----

    public function test_the_rule_list_says_what_an_amount_is_charged_per(): void
    {
        $perPax = PricingRule::factory()->perPax(350)->make();
        $flat = PricingRule::factory()->fixed(350)->make();

        $this->assertSame('350.00 per passenger', $perPax->amountLabel());
        $this->assertSame('350.00', $flat->amountLabel(), 'a booking fee carries no unit');
        $this->assertSame('200.00 per room-night', PricingRule::factory()->perRoomNight(200)->make()->amountLabel());
        $this->assertSame('No markup', PricingRule::factory()->none()->make()->amountLabel());
        $this->assertSame('20%', PricingRule::factory()->margin(20)->make()->amountLabel());
    }
}
