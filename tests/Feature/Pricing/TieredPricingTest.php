<?php

namespace Tests\Feature\Pricing;

use App\Enums\BookingProduct;
use App\Enums\CalcType;
use App\Enums\Supplier;
use App\Enums\TierMode;
use App\Enums\TierUnit;
use App\Enums\TravelScope;
use App\Models\Agency;
use App\Models\PricingRule;
use App\Models\PricingStrategy;
use App\Services\Pricing\Exceptions\PricingException;
use App\Services\Pricing\Money;
use App\Services\Pricing\NetPrice;
use App\Services\Pricing\PricingContext;
use App\Services\Pricing\PricingEngine;
use App\Services\Pricing\StrategyResolver;
use App\Services\Pricing\TieredBands;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tier tables: the arithmetic, the shapes that are refused, and the cliff.
 *
 * The table itself is checked against TieredBands, because that is what both the engine
 * and the form ask; the prices are taken through the real engine, because a band is only
 * correct after the basis is resolved and the floor and cap are applied.
 */
class TieredPricingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $mainOffice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mainOffice = Agency::factory()->create(['name' => 'Main Office']);

        app(Settings::class)->set(StrategyResolver::MAIN_OFFICE_SETTING, (string) $this->mainOffice->id);
    }

    /**
     * 12% under 10,000, 8% to 50,000, 5% above — the table everybody asks for.
     *
     * It is only writable BY SLICE. Charged on the whole fare, 12% of 10,000 is 1,200 and
     * 8% of 10,001 is 800, so it falls at every boundary — which is what the cliff check
     * refuses, and why marginal mode exists.
     */
    private function fallingBands(): array
    {
        return [
            [10000, CalcType::PercentageMarkup, 12],
            [50000, CalcType::PercentageMarkup, 8],
            [null, CalcType::PercentageMarkup, 5],
        ];
    }

    /** A table that climbs, so it is legal charged on the whole fare. */
    private function climbingBands(): array
    {
        return [
            [10000, CalcType::Fixed, 800],
            [50000, CalcType::PercentageMarkup, 10],
            [null, CalcType::PercentageMarkup, 12],
        ];
    }

    private function ruleWith(array $bands, TierMode $mode = TierMode::Whole, TierUnit $unit = TierUnit::Booking): PricingRule
    {
        return PricingRule::factory()
            ->for(PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id]), 'strategy')
            ->tiered($bands, $mode, $unit)
            ->create();
    }

    private function sell(float $net, int $pax = 1, TravelScope $scope = TravelScope::Domestic): string
    {
        return $this->quote(new PricingContext(
            product: BookingProduct::Flight,
            supplier: Supplier::TboAir,
            scope: $scope,
            net: NetPrice::of($net),
            paxCount: $pax,
        ));
    }

    private function sellHotel(float $net, int $rooms, int $nights): string
    {
        return $this->quote(new PricingContext(
            product: BookingProduct::Hotel,
            supplier: Supplier::TboHotel,
            scope: TravelScope::Domestic,
            net: NetPrice::of($net),
            roomCount: $rooms,
            nights: $nights,
        ));
    }

    private function quote(PricingContext $context): string
    {
        return (string) app(PricingEngine::class)->quote($context, $this->mainOffice)->sell->amount;
    }

    private static function params(array $bands, TierMode $mode = TierMode::Whole, TierUnit $unit = TierUnit::Booking): array
    {
        return ['mode' => $mode->value, 'bands_on' => $unit->value, 'bands' => array_map(fn (array $band): array => [
            'up_to' => $band[0] === null ? null : (string) $band[0],
            'calc_type' => $band[1]->value,
            'value' => (string) $band[2],
        ], $bands)];
    }

    // ------------------------------------------- charged on the whole fare ----

    public function test_the_band_a_fare_lands_in_charges_the_whole_fare(): void
    {
        $this->ruleWith($this->climbingBands());

        $this->assertSame('5800.00', $this->sell(5000), 'the flat band');
        $this->assertSame('33000.00', $this->sell(30000), '10% of the whole 30,000');
        $this->assertSame('112000.00', $this->sell(100000), '12% of the whole 100,000');
    }

    public function test_the_upper_limit_of_a_band_belongs_to_that_band(): void
    {
        // Inclusive, and stated as such on TieredBand::covers(). A boundary belonging to
        // neither band would leave one exact fare in the table with no rule at all.
        $this->ruleWith($this->climbingBands());

        $this->assertSame('10800.00', $this->sell(10000), 'exactly 10,000 is still the flat band');
        $this->assertSame('11001.10', $this->sell(10001), 'a peso more is the 10% band');
    }

    public function test_a_fare_above_the_table_is_priced_by_the_open_band(): void
    {
        $this->ruleWith($this->climbingBands());

        $this->assertSame('1120000.00', $this->sell(1000000));
    }

    // ------------------------------------------------- charged by slice ----

    public function test_each_band_charges_only_its_own_slice(): void
    {
        // The way tax brackets work. 30,000 is 12% of the first 10,000 plus 8% of the
        // next 20,000 — 2,800, not the 2,400 that whole-fare pricing would take.
        $this->ruleWith($this->fallingBands(), TierMode::Marginal);

        $this->assertSame('5600.00', $this->sell(5000), 'inside the first band, the two modes agree');
        $this->assertSame('32800.00', $this->sell(30000), '1,200 + 1,600');
        $this->assertSame('106900.00', $this->sell(100000), '1,200 + 3,200 + 2,500');
    }

    public function test_charging_by_slice_never_falls_at_a_boundary(): void
    {
        // The whole point. The same 12/8/5 table is illegal charged on the whole fare;
        // by slice it climbs through every boundary.
        $this->ruleWith($this->fallingBands(), TierMode::Marginal);

        $this->assertSame('11200.00', $this->sell(10000));
        $this->assertSame('11201.08', $this->sell(10001), 'a peso more costs more, not 400 less');
    }

    public function test_a_flat_band_is_charged_once_and_only_once_reached(): void
    {
        $this->ruleWith([
            [10000, CalcType::Fixed, 800],
            [null, CalcType::PercentageMarkup, 8],
        ], TierMode::Marginal);

        $this->assertSame('5800.00', $this->sell(5000), 'the flat band, with no slice above it');
        $this->assertSame('21600.00', $this->sell(20000), '800 for the first slice, 8% of the next 10,000');
    }

    // -------------------------------------------------------- either way ----

    public function test_a_tiered_rule_ignores_its_own_value(): void
    {
        // `value` is NOT NULL, so a tiered rule carries one. Nothing may read it.
        $rule = $this->ruleWith($this->climbingBands());
        $rule->forceFill(['value' => '9999'])->save();

        $this->assertSame('5800.00', $this->sell(5000));
    }

    public function test_the_floor_and_cap_still_bound_a_tiered_contribution(): void
    {
        $rule = $this->ruleWith($this->fallingBands(), TierMode::Marginal);
        $rule->forceFill(['min_markup' => '1000.00', 'max_markup' => '2000.00'])->save();

        $this->assertSame('6000.00', $this->sell(5000), '12% of 5,000 is 600, lifted to the 1,000 floor');
        $this->assertSame('32000.00', $this->sell(30000), 'the slices come to 2,800, held to the 2,000 cap');
    }

    // ------------------------------------------------ what the bands read ----

    public function test_a_flight_table_is_read_one_ticket_at_a_time(): void
    {
        // Three seats at 10,000 are three 10,000 tickets, not one 30,000 booking that has
        // climbed out of the band it belongs in. Same table, same fare, different reading.
        $this->ruleWith($this->fallingBands(), TierMode::Marginal, TierUnit::Passenger);

        $this->assertSame('33600.00', $this->sell(30000, 3), '12% of each 10,000 ticket, three times');
    }

    public function test_the_same_table_read_at_the_booking_prices_it_differently(): void
    {
        $this->ruleWith($this->fallingBands(), TierMode::Marginal);

        $this->assertSame('32800.00', $this->sell(30000, 3), '1,200 + 1,600 across the whole 30,000');
    }

    public function test_a_flat_band_read_per_ticket_is_an_amount_per_ticket(): void
    {
        // 10,000 a seat lands each ticket in the flat band. The natural reading, and the
        // only one that makes a flat band useful in a per-ticket table.
        $this->ruleWith($this->climbingBands(), TierMode::Whole, TierUnit::Passenger);

        $this->assertSame('32400.00', $this->sell(30000, 3), '800 a ticket, three times');
        $this->assertSame('33000.00', $this->sell(30000), 'one passenger reads the whole 30,000 either way');
    }

    public function test_a_hotel_table_can_be_read_one_room_night_at_a_time(): void
    {
        // The axis a hotel rate moves on. Two rooms for three nights is six of them, so
        // 30,000 is read at 5,000 — and head count deliberately does not enter into it.
        $this->ruleWith($this->climbingBands(), TierMode::Whole, TierUnit::RoomNight);

        $this->assertSame('34800.00', $this->sellHotel(30000, 2, 3), '800 a room-night, six times');
    }

    public function test_a_booking_always_holds_at_least_one_unit(): void
    {
        // A table read at a zero fare would band every booking in its cheapest rung.
        $context = new PricingContext(
            product: BookingProduct::Flight,
            supplier: Supplier::TboAir,
            scope: TravelScope::Domestic,
            net: NetPrice::of(5000),
            paxCount: 0,
        );

        $this->assertSame(1, TierUnit::Passenger->unitsIn($context));
        $this->assertSame(1, TierUnit::RoomNight->unitsIn($context));
    }

    public function test_the_unit_belongs_to_the_product(): void
    {
        $this->assertTrue(TierUnit::Passenger->appliesToProduct('flight'));
        $this->assertFalse(TierUnit::Passenger->appliesToProduct('hotel'));
        $this->assertFalse(TierUnit::Passenger->appliesToProduct('*'), 'one rule cannot divide two ways');

        $this->assertTrue(TierUnit::RoomNight->appliesToProduct('hotel'));
        $this->assertFalse(TierUnit::RoomNight->appliesToProduct('flight'));

        // The whole booking means the same thing everywhere, so it is offered everywhere.
        foreach (['*', 'flight', 'hotel'] as $product) {
            $this->assertTrue(TierUnit::Booking->appliesToProduct($product));
        }
    }

    public function test_a_flight_reads_per_ticket_by_default_and_nothing_else_does(): void
    {
        $this->assertSame(TierUnit::Passenger, TierUnit::defaultFor('flight'));
        $this->assertSame(TierUnit::Booking, TierUnit::defaultFor('hotel'));
        $this->assertSame(TierUnit::Booking, TierUnit::defaultFor('*'));
    }

    public function test_the_unit_cannot_move_a_table_across_a_boundary(): void
    {
        // The unit count multiplies the whole table uniformly, so a table that is legal
        // read at the booking is legal read at a ticket. This is why a per-passenger BAND
        // is refused while a per-passenger READING is not.
        foreach (TierUnit::cases() as $unit) {
            $this->assertSame([], TieredBands::problems(
                self::params($this->climbingBands(), TierMode::Whole, $unit),
            ));
            $this->assertNotSame([], TieredBands::problems(
                self::params($this->fallingBands(), TierMode::Whole, $unit),
            ));
        }
    }

    public function test_a_table_that_says_neither_unit_is_refused(): void
    {
        $problems = TieredBands::problems(
            ['bands_on' => 'per_head', 'bands' => self::params($this->climbingBands())['bands']],
        );

        $this->assertStringContainsString('the whole booking or one unit of it', $problems[0]);
    }

    // ---------------------------------------------- a real handling-fee table ----

    public function test_a_handling_fee_table_per_service_line(): void
    {
        // The live system's "Service Lines Handling Fees": a flat fee per ticket, banded
        // by fare, with a separate table for domestic and international. One rule per
        // service line, three bands each — no new concept, and no rule left over.
        $strategy = PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id]);

        PricingRule::factory()->for($strategy, 'strategy')->forProduct('flight')->scoped('domestic')
            ->tiered([
                [10000, CalcType::Fixed, 300],
                [30000, CalcType::Fixed, 500],
                [null, CalcType::Fixed, 1000],
            ], TierMode::Whole, TierUnit::Passenger)
            ->create(['description' => 'Domestic flight handling fee']);

        PricingRule::factory()->for($strategy, 'strategy')->forProduct('flight')->scoped('international')
            ->tiered([
                [20000, CalcType::Fixed, 750],
                [50000, CalcType::Fixed, 1500],
                [null, CalcType::Fixed, 3000],
            ], TierMode::Whole, TierUnit::Passenger)
            ->create(['description' => 'International flight handling fee']);

        // Domestic, one ticket, one band each.
        $this->assertSame('8300.00', $this->sell(8000), 'class 1');
        $this->assertSame('25500.00', $this->sell(25000), 'class 2');
        $this->assertSame('51000.00', $this->sell(50000), 'class 3');

        // International is its own table on the same fares.
        $this->assertSame('8750.00', $this->sell(8000, 1, TravelScope::International), 'class 1');
        $this->assertSame('26500.00', $this->sell(25000, 1, TravelScope::International), 'class 2');
        $this->assertSame('63000.00', $this->sell(60000, 1, TravelScope::International), 'class 3');

        // The limit belongs to the band that names it, which is what the live table's
        // "20,001" and "50,001" lower bounds mean: 50,000 is still class 2.
        $this->assertSame('51500.00', $this->sell(50000, 1, TravelScope::International));
        $this->assertSame('53001.00', $this->sell(50001, 1, TravelScope::International));

        // And the fee is per ticket: three seats at 8,000 are three class-1 tickets, not
        // one 24,000 booking that has climbed into class 2.
        $this->assertSame('24900.00', $this->sell(24000, 3), '300 a ticket, three times');
    }

    // ------------------------------------------------------------- the audit trail ----

    public function test_the_bands_travel_into_the_booking_snapshot(): void
    {
        // A price made on today's bands has to still explain itself after they move.
        $rule = $this->ruleWith($this->fallingBands(), TierMode::Marginal);

        $this->assertSame($rule->params, $rule->snapshot()['params']);
        $this->assertSame('marginal', $rule->snapshot()['params']['mode'], 'the mode decides the arithmetic');
    }

    public function test_a_priced_rung_names_the_band_it_landed_in(): void
    {
        // The table alone does not explain the number; the band that fired does.
        $this->ruleWith($this->climbingBands());

        $context = new PricingContext(
            product: BookingProduct::Flight,
            supplier: Supplier::TboAir,
            scope: TravelScope::Domestic,
            net: NetPrice::of(30000),
        );

        $layer = app(PricingEngine::class)->quote($context, $this->mainOffice)->layers[0];

        $this->assertSame('10% (10,000.00–50,000.00)', $layer->amountLabel());
    }

    public function test_a_per_ticket_table_says_so_wherever_it_is_shown(): void
    {
        // Without it a rung reading "12%" against a 30,000 fare that was charged 3,600
        // looks like arithmetic nobody can follow.
        $rule = $this->ruleWith($this->fallingBands(), TierMode::Marginal, TierUnit::Passenger);

        $this->assertSame('Tiered: 12% / 8% / 5%, per passenger', $rule->amountLabel());

        $context = new PricingContext(
            product: BookingProduct::Flight,
            supplier: Supplier::TboAir,
            scope: TravelScope::Domestic,
            net: NetPrice::of(30000),
            paxCount: 3,
        );

        $layer = app(PricingEngine::class)->quote($context, $this->mainOffice)->layers[0];

        // The band is not named: a rung records the booking's basis, not its head count,
        // so which band fired cannot be recovered from it. The table and its unit can.
        $this->assertSame('12% / 8% / 5%, per passenger', $layer->amountLabel());
        $this->assertSame('3600.00', (string) $layer->markup);
    }

    public function test_the_rule_list_shows_the_shape_of_the_table(): void
    {
        $rule = $this->ruleWith($this->fallingBands(), TierMode::Marginal);

        $this->assertSame('Tiered: 12% / 8% / 5%', $rule->amountLabel());
    }

    public function test_an_unreadable_table_labels_as_the_type_rather_than_throwing(): void
    {
        // A label is not the place to discover a broken rule — the form is.
        $this->assertSame('Tiered by amount', TieredBands::label(null));
        $this->assertSame('Tiered by amount', TieredBands::labelFor(['bands' => []], Money::of(5000)));
    }

    // ------------------------------------------------------------------ the cliff ----

    public function test_a_table_that_pays_less_on_a_dearer_fare_is_refused(): void
    {
        // 12% of 10,000 is 1,200; 8% of 10,001 is 800. A fare one peso more expensive
        // would sell for 399 less. This is the whole reason the check exists.
        $problems = TieredBands::problems(self::params([
            [10000, CalcType::PercentageMarkup, 12],
            [null, CalcType::PercentageMarkup, 8],
        ]));

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('10,000.00', $problems[0]);
        $this->assertStringContainsString('the more expensive fare sells for less', $problems[0]);
        // ...and says what to do about it, because the fix is not obvious.
        $this->assertStringContainsString('charge the bands by slice', $problems[0]);
    }

    public function test_a_table_that_only_climbs_at_the_boundary_is_accepted(): void
    {
        // 800 flat up to 10,000, then 8% — which is exactly 800 at the boundary. Equal is
        // fine; it is the fall that is the bug.
        $this->assertSame([], TieredBands::problems(self::params([
            [10000, CalcType::Fixed, 800],
            [null, CalcType::PercentageMarkup, 8],
        ])));
    }

    public function test_a_table_that_climbs_through_every_boundary_is_accepted(): void
    {
        $this->assertSame([], TieredBands::problems(self::params($this->climbingBands())));
    }

    public function test_falling_rates_are_accepted_the_moment_they_are_charged_by_slice(): void
    {
        // The same 12/8/5 table, refused above and accepted here. Charged by slice it
        // cannot invert, so there is nothing left to refuse — which is the whole reason
        // the mode exists rather than the check simply being a warning.
        $this->assertNotSame([], TieredBands::problems(self::params($this->fallingBands())));
        $this->assertSame([], TieredBands::problems(self::params($this->fallingBands(), TierMode::Marginal)));
    }

    public function test_a_table_that_says_neither_mode_is_refused(): void
    {
        $problems = TieredBands::problems(
            ['mode' => 'somehow', 'bands' => self::params($this->climbingBands())['bands']],
        );

        $this->assertStringContainsString('on the whole fare or by slice', $problems[0]);
    }

    // ------------------------------------------------------------ the shapes refused ----

    public function test_a_table_needs_more_than_one_band(): void
    {
        $this->assertSame(
            ['a table with one band is just that band — use its type directly instead.'],
            TieredBands::problems(self::params([[null, CalcType::PercentageMarkup, 10]])),
        );

        $this->assertNotSame([], TieredBands::problems(null));
        $this->assertNotSame([], TieredBands::problems(['bands' => []]));
    }

    public function test_the_upper_limits_have_to_climb(): void
    {
        $problems = TieredBands::problems(self::params([
            [50000, CalcType::PercentageMarkup, 12],
            [10000, CalcType::PercentageMarkup, 8],
            [null, CalcType::PercentageMarkup, 5],
        ]));

        $this->assertStringContainsString('the upper limits have to climb', $problems[0]);
    }

    public function test_only_the_last_band_may_be_open_ended(): void
    {
        $problems = TieredBands::problems(self::params([
            [null, CalcType::PercentageMarkup, 12],
            [50000, CalcType::PercentageMarkup, 8],
        ]));

        $this->assertStringContainsString('no band below it can ever be reached', $problems[0]);
    }

    public function test_the_last_band_must_be_open_ended(): void
    {
        // Without it a fare above the table has no band, and the engine would have to
        // invent an answer.
        $problems = TieredBands::problems(self::params([
            [10000, CalcType::PercentageMarkup, 12],
            [50000, CalcType::PercentageMarkup, 8],
        ]));

        $this->assertStringContainsString('the last band needs an empty upper limit', $problems[0]);
    }

    public function test_a_band_cannot_be_charged_per_passenger(): void
    {
        // It depends on the booking, so the table could not be checked for the cliff when
        // somebody wrote it. Charge per passenger with a second rule; they add up.
        $problems = TieredBands::problems(self::params([
            [10000, CalcType::PerPax, 350],
            [null, CalcType::PercentageMarkup, 8],
        ]));

        $this->assertStringContainsString('charged in a way a band cannot be', $problems[0]);
    }

    public function test_a_band_refuses_the_numbers_its_type_cannot_take(): void
    {
        $negative = TieredBands::problems(self::params([
            [10000, CalcType::Fixed, -50],
            [null, CalcType::PercentageMarkup, 8],
        ]));
        $this->assertStringContainsString('needs an amount of zero or more', $negative[0]);

        $margin = TieredBands::problems(self::params([
            [10000, CalcType::PercentageMargin, 100],
            [null, CalcType::PercentageMarkup, 8],
        ]));
        $this->assertStringContainsString('cannot be reached', $margin[0]);
    }

    public function test_a_table_that_cannot_be_read_throws_rather_than_pricing(): void
    {
        // Reaching this means something bypassed the form. A fare quietly cheaper than
        // intended is the worst possible way to find out about a broken rule.
        $this->expectException(PricingException::class);

        TieredBands::fromParams(['bands' => [['up_to' => null, 'calc_type' => 'fixed', 'value' => '1']]]);
    }
}
