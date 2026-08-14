<?php

namespace Tests\Feature\Pricing;

use App\Enums\BookingProduct;
use App\Enums\PricingBasis;
use App\Enums\Supplier;
use App\Enums\TravelScope;
use App\Models\Agency;
use App\Models\PricingRule;
use App\Models\PricingStrategy;
use App\Services\Pricing\Exceptions\PricingException;
use App\Services\Pricing\NetPrice;
use App\Services\Pricing\PricingContext;
use App\Services\Pricing\PricingEngine;
use App\Services\Pricing\StrategyResolver;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The arithmetic the whole feature rests on: Main Office + Agency, cumulative.
 *
 * First match wins within a level; levels sum.
 */
class PricingEngineTest extends TestCase
{
    use RefreshDatabase;

    private Agency $mainOffice;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mainOffice = Agency::factory()->create(['name' => 'Main Office']);
        $this->agency = Agency::factory()->create(['name' => 'Agency ABC']);

        app(Settings::class)->set(StrategyResolver::MAIN_OFFICE_SETTING, (string) $this->mainOffice->id);
    }

    private function engine(): PricingEngine
    {
        return app(PricingEngine::class);
    }

    private function strategyFor(Agency $agency): PricingStrategy
    {
        return PricingStrategy::factory()->create(['agency_id' => $agency->id]);
    }

    private function context(float $net = 5000, array $overrides = []): PricingContext
    {
        return new PricingContext(
            product: $overrides['product'] ?? BookingProduct::Flight,
            supplier: $overrides['supplier'] ?? Supplier::TboAir,
            scope: $overrides['scope'] ?? TravelScope::Domestic,
            net: NetPrice::of($net),
            attributes: $overrides['attributes'] ?? [],
        );
    }

    // ------------------------------------------------------------ the core sum ----

    public function test_the_worked_example(): void
    {
        PricingRule::factory()->fixed(500)->create(['pricing_strategy_id' => $this->strategyFor($this->mainOffice)->id]);
        PricingRule::factory()->fixed(200)->create(['pricing_strategy_id' => $this->strategyFor($this->agency)->id]);

        $price = $this->engine()->quote($this->context(5000), $this->agency);

        $this->assertSame('5000.00', (string) $price->net->amount);
        $this->assertSame('5500.00', (string) $price->cost(), 'net + Main Office');
        $this->assertSame('5700.00', (string) $price->sell->amount, 'net + Main Office + Agency');
        $this->assertSame('700.00', (string) $price->markupTotal());
        $this->assertSame('200.00', (string) $price->ownMargin());

        $this->assertCount(2, $price->layers);
        $this->assertSame(0, $price->layers[0]->level);
        $this->assertSame('500.00', (string) $price->layers[0]->markup);
        $this->assertSame(1, $price->layers[1]->level);
        $this->assertSame('200.00', (string) $price->layers[1]->markup);
    }

    public function test_the_agency_markup_adds_to_the_main_office_and_does_not_replace_it(): void
    {
        PricingRule::factory()->fixed(500)->create(['pricing_strategy_id' => $this->strategyFor($this->mainOffice)->id]);
        PricingRule::factory()->fixed(200)->create(['pricing_strategy_id' => $this->strategyFor($this->agency)->id]);

        $price = $this->engine()->quote($this->context(5000), $this->agency);

        // An override chain would have sold at 5,200. It is cumulative.
        $this->assertNotSame('5200.00', (string) $price->sell->amount);
        $this->assertSame('5700.00', (string) $price->sell->amount);
    }

    public function test_two_percentages_both_work_from_net_by_default(): void
    {
        PricingRule::factory()->percentage(10)->create(['pricing_strategy_id' => $this->strategyFor($this->mainOffice)->id]);
        PricingRule::factory()->percentage(10)->create(['pricing_strategy_id' => $this->strategyFor($this->agency)->id]);

        $price = $this->engine()->quote($this->context(5000), $this->agency);

        // 500 + 500, not 500 + 550. The second level does not compound.
        $this->assertSame('500.00', (string) $price->layers[0]->markup);
        $this->assertSame('500.00', (string) $price->layers[1]->markup);
        $this->assertSame('6000.00', (string) $price->sell->amount);
    }

    public function test_a_running_basis_compounds_when_it_is_asked_to(): void
    {
        PricingRule::factory()->percentage(10)->create(['pricing_strategy_id' => $this->strategyFor($this->mainOffice)->id]);
        PricingRule::factory()->percentage(10)->create([
            'pricing_strategy_id' => $this->strategyFor($this->agency)->id,
            'basis' => PricingBasis::Running,
        ]);

        $price = $this->engine()->quote($this->context(5000), $this->agency);

        $this->assertSame('550.00', (string) $price->layers[1]->markup, '10% of 5,500');
        $this->assertSame('6050.00', (string) $price->sell->amount);
    }

    public function test_the_order_of_levels_does_not_change_a_net_based_total(): void
    {
        // Addition commutes, which is a large part of why `net` is the default.
        PricingRule::factory()->percentage(10)->create(['pricing_strategy_id' => $this->strategyFor($this->mainOffice)->id]);
        PricingRule::factory()->fixed(200)->create(['pricing_strategy_id' => $this->strategyFor($this->agency)->id]);

        $this->assertSame('5700.00', (string) $this->engine()->quote($this->context(5000), $this->agency)->sell->amount);
    }

    // ---------------------------------------------------------- missing levels ----

    public function test_an_empty_configuration_sells_at_net(): void
    {
        // What ships in Phase 3: the engine runs, contributes nothing, moves no price.
        $price = $this->engine()->quote($this->context(5000), $this->agency);

        $this->assertSame('5000.00', (string) $price->sell->amount);
        $this->assertSame('5000.00', (string) $price->cost());
        $this->assertSame([], $price->layers);
    }

    public function test_an_agency_with_no_strategy_still_pays_the_main_office(): void
    {
        PricingRule::factory()->fixed(500)->create(['pricing_strategy_id' => $this->strategyFor($this->mainOffice)->id]);

        $price = $this->engine()->quote($this->context(5000), $this->agency);

        $this->assertSame('5500.00', (string) $price->sell->amount);
        $this->assertCount(1, $price->layers);
    }

    public function test_a_paused_strategy_contributes_nothing_and_is_not_an_error(): void
    {
        $strategy = $this->strategyFor($this->agency);
        $strategy->update(['is_active' => false]);
        PricingRule::factory()->fixed(200)->create(['pricing_strategy_id' => $strategy->id]);
        PricingRule::factory()->fixed(500)->create(['pricing_strategy_id' => $this->strategyFor($this->mainOffice)->id]);

        $this->assertSame('5500.00', (string) $this->engine()->quote($this->context(5000), $this->agency)->sell->amount);
    }

    public function test_a_strategy_whose_rules_all_miss_contributes_nothing(): void
    {
        PricingRule::factory()->fixed(500)->create(['pricing_strategy_id' => $this->strategyFor($this->mainOffice)->id]);
        PricingRule::factory()->fixed(200)->forProduct('hotel')->create([
            'pricing_strategy_id' => $this->strategyFor($this->agency)->id,
        ]);

        // The context is a flight.
        $this->assertSame('5500.00', (string) $this->engine()->quote($this->context(5000), $this->agency)->sell->amount);
    }

    // ------------------------------------------------------- first match wins ----

    public function test_only_one_rule_per_level_contributes(): void
    {
        $strategy = $this->strategyFor($this->mainOffice);
        PricingRule::factory()->fixed(500)->priority(10)->create(['pricing_strategy_id' => $strategy->id]);
        PricingRule::factory()->fixed(999)->priority(20)->create(['pricing_strategy_id' => $strategy->id]);

        $price = $this->engine()->quote($this->context(5000), $this->agency);

        $this->assertCount(1, $price->layers);
        $this->assertSame('5500.00', (string) $price->sell->amount, 'not 6,499 — rules do not sum');
    }

    public function test_a_narrower_rule_above_a_catch_all_wins(): void
    {
        $strategy = $this->strategyFor($this->mainOffice);
        PricingRule::factory()->fixed(800)->scoped('international')->priority(10)->create(['pricing_strategy_id' => $strategy->id]);
        PricingRule::factory()->fixed(300)->priority(900)->create(['pricing_strategy_id' => $strategy->id]);

        $domestic = $this->engine()->quote($this->context(5000), $this->agency);
        $this->assertSame('5300.00', (string) $domestic->sell->amount, 'falls through to the catch-all');

        $international = $this->engine()->quote(
            $this->context(5000, ['scope' => TravelScope::International]),
            $this->agency,
        );
        $this->assertSame('5800.00', (string) $international->sell->amount, 'the narrow rule wins');
    }

    // ------------------------------------------------------------ Main Office ----

    public function test_a_main_office_user_does_not_pay_the_main_office_markup_twice(): void
    {
        PricingRule::factory()->fixed(500)->create(['pricing_strategy_id' => $this->strategyFor($this->mainOffice)->id]);

        $price = $this->engine()->quote($this->context(5000), $this->mainOffice);

        $this->assertCount(1, $price->layers);
        $this->assertSame('5500.00', (string) $price->sell->amount, 'not 6,000');
        $this->assertSame('5000.00', (string) $price->cost(), 'nothing above level 0');
    }

    public function test_platform_staff_resolve_to_the_main_office_alone(): void
    {
        PricingRule::factory()->fixed(500)->create(['pricing_strategy_id' => $this->strategyFor($this->mainOffice)->id]);
        PricingRule::factory()->fixed(200)->create(['pricing_strategy_id' => $this->strategyFor($this->agency)->id]);

        $price = $this->engine()->quote($this->context(5000), null);

        $this->assertCount(1, $price->layers);
        $this->assertSame('5500.00', (string) $price->sell->amount);
    }

    public function test_it_refuses_to_price_with_no_main_office_configured(): void
    {
        app(Settings::class)->forget(StrategyResolver::MAIN_OFFICE_SETTING);

        $this->expectException(PricingException::class);
        $this->expectExceptionMessage('No Main Office is configured');

        $this->engine()->quote($this->context(5000), $this->agency);
    }

    public function test_it_refuses_to_price_when_the_configured_main_office_is_gone(): void
    {
        app(Settings::class)->set(StrategyResolver::MAIN_OFFICE_SETTING, '999999');

        $this->expectException(PricingException::class);
        $this->expectExceptionMessage('no longer exists');

        $this->engine()->quote($this->context(5000), $this->agency);
    }

    // ------------------------------------------------------- floors, caps, etc ----

    public function test_a_floor_and_a_cap_bound_one_rule(): void
    {
        PricingRule::factory()->percentage(10)->create([
            'pricing_strategy_id' => $this->strategyFor($this->mainOffice)->id,
            'min_markup' => '500.00',
            'max_markup' => '3000.00',
        ]);

        // 10% of 1,000 is 100 — lifted to the floor.
        $this->assertSame('1500.00', (string) $this->engine()->quote($this->context(1000), $this->agency)->sell->amount);

        // 10% of 100,000 is 10,000 — held to the cap.
        $this->assertSame('103000.00', (string) $this->engine()->quote($this->context(100000), $this->agency)->sell->amount);
    }

    public function test_the_platform_ceiling_caps_the_whole_ladder(): void
    {
        config(['pricing.max_total_markup' => '600']);

        PricingRule::factory()->fixed(500)->create(['pricing_strategy_id' => $this->strategyFor($this->mainOffice)->id]);
        PricingRule::factory()->fixed(400)->create(['pricing_strategy_id' => $this->strategyFor($this->agency)->id]);

        // 900 of markup asked for, 600 allowed.
        $this->assertSame('5600.00', (string) $this->engine()->quote($this->context(5000), $this->agency)->sell->amount);
    }

    public function test_the_selling_price_is_rounded_once_at_the_end(): void
    {
        config(['pricing.rounding' => 50]);

        PricingRule::factory()->percentage(10)->create(['pricing_strategy_id' => $this->strategyFor($this->mainOffice)->id]);
        PricingRule::factory()->percentage(5)->create(['pricing_strategy_id' => $this->strategyFor($this->agency)->id]);

        $price = $this->engine()->quote($this->context('1234.56'), $this->agency);

        // 1,234.56 + 123.46 + 61.73 = 1,419.75, rounded up to 1,450.00 at a 50 step.
        $this->assertSame('1450.00', (string) $price->sell->amount);
        $this->assertSame('30.25', (string) $price->roundingDelta);

        // The rungs keep their exact values, so the breakdown still explains itself —
        // rounding each rung instead would leave the layers not summing to the total.
        $this->assertSame('123.46', (string) $price->layers[0]->markup);
        $this->assertSame('61.73', (string) $price->layers[1]->markup);
        $this->assertSame('1419.75', (string) $price->layers[1]->runningTotal);
    }

    public function test_a_negative_markup_is_refused_rather_than_treated_as_a_discount(): void
    {
        PricingRule::factory()->fixed(-100)->create(['pricing_strategy_id' => $this->strategyFor($this->mainOffice)->id]);

        $this->expectException(PricingException::class);
        $this->expectExceptionMessage('negative markup');

        $this->engine()->quote($this->context(5000), $this->agency);
    }

    // ------------------------------------------------------------- extensible ----

    public function test_the_engine_loops_the_chain_and_does_not_know_how_long_it_is(): void
    {
        // The extensibility claim, asserted rather than promised: the engine's output
        // is a function of what the resolver returns. One level in, one layer out.
        PricingRule::factory()->fixed(500)->create(['pricing_strategy_id' => $this->strategyFor($this->mainOffice)->id]);

        $oneLevel = $this->engine()->quote($this->context(5000), $this->mainOffice);
        $this->assertCount(1, $oneLevel->layers);

        PricingRule::factory()->fixed(200)->create(['pricing_strategy_id' => $this->strategyFor($this->agency)->id]);

        $twoLevels = $this->engine()->quote($this->context(5000), $this->agency);
        $this->assertCount(2, $twoLevels->layers);
    }
}
