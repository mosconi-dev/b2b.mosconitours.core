<?php

namespace Tests\Feature\Pricing;

use App\Enums\BookingProduct;
use App\Enums\CalcType;
use App\Enums\Supplier;
use App\Enums\TravelScope;
use App\Models\Agency;
use App\Models\PricingRule;
use App\Models\PricingStrategy;
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

    public function test_tiered_is_declared_but_not_offered(): void
    {
        // It carries bands rather than one number and has nowhere to put them yet.
        $this->assertNotContains(CalcType::Tiered, CalcType::implemented());
        $this->assertArrayNotHasKey('tiered', CalcType::options());
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
