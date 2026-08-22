<?php

namespace Tests\Feature\Pricing;

use App\Enums\AppliesTo;
use App\Enums\BookingProduct;
use App\Enums\CalcType;
use App\Enums\TierMode;
use App\Enums\TierUnit;
use App\Models\Agency;
use App\Models\PricingRule;
use App\Models\PricingStrategy;
use App\Models\User;
use App\Services\Pricing\StrategyResolver;
use App\Services\Settings\Settings;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The Main Office pricing screens, and the permissions around them.
 */
class PricingAdminTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    private Agency $mainOffice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->mainOffice = Agency::factory()->create(['name' => 'Main Office']);
    }

    private function configureRoot(): void
    {
        app(Settings::class)->set(StrategyResolver::MAIN_OFFICE_SETTING, (string) $this->mainOffice->id);
    }

    private function editor(): User
    {
        return $this->userWith(['admin.access', 'markup.office.view', 'markup.office.edit']);
    }

    // ---------------------------------------------------------------- access ----

    public function test_the_page_needs_the_office_markup_permission(): void
    {
        $this->configureRoot();

        $this->actingAs($this->userWith(['admin.access']))
            ->get(route('admin.pricing.index'))
            ->assertForbidden();
    }

    public function test_a_viewer_may_read_but_not_write(): void
    {
        $this->configureRoot();
        $viewer = $this->userWith(['admin.access', 'markup.office.view']);

        $this->actingAs($viewer)->get(route('admin.pricing.index'))->assertOk();

        $this->actingAs($viewer)->post(route('admin.pricing.rules.store'), $this->ruleData())
            ->assertForbidden();
    }

    public function test_an_agency_markup_permission_does_not_reach_the_office_screen(): void
    {
        // `markup` and `markup.office` are separate abilities precisely because editing
        // the office level moves every partner's cost.
        $this->configureRoot();

        $this->actingAs($this->userWith(['admin.access', 'markup.view', 'markup.edit']))
            ->get(route('admin.pricing.index'))
            ->assertForbidden();
    }

    // ------------------------------------------------------------ pricing root ----

    public function test_the_page_asks_for_a_pricing_root_when_none_is_set(): void
    {
        $this->actingAs($this->editor())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            ->assertSee('No pricing root is configured');
    }

    public function test_setting_the_pricing_root(): void
    {
        $this->actingAs($this->editor())
            ->put(route('admin.pricing.main-office'), ['agency_id' => $this->mainOffice->id])
            ->assertRedirect();

        $this->assertSame(
            (string) $this->mainOffice->id,
            app(Settings::class)->get(StrategyResolver::MAIN_OFFICE_SETTING),
        );
    }

    public function test_a_deactivated_agency_cannot_be_the_pricing_root(): void
    {
        $this->mainOffice->update(['is_active' => false]);

        $this->actingAs($this->editor())
            ->put(route('admin.pricing.main-office'), ['agency_id' => $this->mainOffice->id])
            ->assertSessionHasErrors('agency_id');
    }

    // ------------------------------------------------------------------ rules ----

    public function test_adding_a_rule(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData(['value' => '500']))
            ->assertRedirect();

        $this->assertDatabaseHas('pricing_rules', ['value' => '500.0000', 'calc_type' => 'fixed']);
    }

    public function test_a_calculator_that_does_not_exist_is_refused_at_the_form(): void
    {
        // Better refused here than discovered mid-quote on a live search. Every declared
        // type has a calculator now, so what this guards against is a value that was
        // never a type at all.
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData(['calc_type' => 'sliding_scale']))
            ->assertSessionHasErrors('calc_type');
    }

    public function test_a_negative_markup_is_refused(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData(['value' => '-100']))
            ->assertSessionHasErrors('value');
    }

    public function test_a_percentage_over_a_hundred_is_questioned(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'calc_type' => 'percentage_markup', 'value' => '1500',
            ]))
            ->assertSessionHasErrors('value');
    }

    public function test_a_per_passenger_fee_is_refused_on_a_hotel_rule(): void
    {
        // The live system multiplied hotel markup by head count: two adults in one
        // double room paid one room rate and two markups. The select greys this out;
        // this is the half that cannot be bypassed.
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'product' => 'hotel', 'calc_type' => 'per_pax', 'value' => '350',
            ]))
            ->assertSessionHasErrors('calc_type');
    }

    public function test_a_per_unit_fee_is_refused_on_a_rule_that_matches_every_product(): void
    {
        $this->configureRoot();

        foreach (['per_pax', 'per_room_night'] as $type) {
            $this->actingAs($this->editor())
                ->post(route('admin.pricing.rules.store'), $this->ruleData([
                    'product' => '*', 'calc_type' => $type, 'value' => '200',
                ]))
                ->assertSessionHasErrors('calc_type');
        }
    }

    public function test_a_per_unit_fee_is_accepted_on_the_product_it_scales_with(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'product' => 'flight', 'calc_type' => 'per_pax', 'value' => '350',
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'product' => 'hotel', 'calc_type' => 'per_room_night', 'value' => '200',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('pricing_rules', ['product' => 'flight', 'calc_type' => 'per_pax']);
        $this->assertDatabaseHas('pricing_rules', ['product' => 'hotel', 'calc_type' => 'per_room_night']);
    }

    public function test_the_form_names_the_restriction_rather_than_hiding_the_type(): void
    {
        // A greyed-out option with no explanation reads as a bug.
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            ->assertSee('flights only')
            ->assertSee('hotels only');
    }

    public function test_a_margin_of_a_hundred_percent_or_more_is_refused(): void
    {
        // A margin is a share of the SELLING price, so 100% of it needs a selling price
        // of infinity. Money::margin() throws on it, and a rule that throws mid-quote is
        // what validating calc types exists to prevent.
        $this->configureRoot();

        foreach (['100', '150'] as $value) {
            $this->actingAs($this->editor())
                ->post(route('admin.pricing.rules.store'), $this->ruleData([
                    'calc_type' => 'percentage_margin', 'value' => $value,
                ]))
                ->assertSessionHasErrors('value');
        }
    }

    public function test_a_margin_just_under_a_hundred_percent_is_allowed(): void
    {
        // Absurd, but arithmetically fine — and refusing it would be this form deciding
        // a business question it was not asked.
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'calc_type' => 'percentage_margin', 'value' => '99',
            ]))
            ->assertSessionHasNoErrors();
    }

    public function test_an_explicit_zero_does_not_ask_for_an_amount(): void
    {
        // "No markup" has no number to give, and `value` is NOT NULL. The form supplies
        // the zero rather than demanding it.
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData(['calc_type' => 'none', 'value' => '']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('pricing_rules', ['calc_type' => 'none', 'value' => '0.0000']);
    }

    public function test_a_cap_below_the_floor_is_refused(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'min_markup' => '500', 'max_markup' => '100',
            ]))
            ->assertSessionHasErrors('max_markup');
    }

    // ---------------------------------------------- the fields the form now has ----

    public function test_a_rule_can_be_narrowed_to_named_airlines(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'product' => 'flight', 'matchers' => '{"airline": ["PR", "5J"]}',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['airline' => ['PR', '5J']], PricingRule::latest('id')->first()->matchers);
    }

    public function test_narrowing_that_is_not_json_is_refused_with_the_shape(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData(['matchers' => 'airline = PR']))
            ->assertSessionHasErrors('matchers');
    }

    public function test_a_misspelt_matcher_key_is_refused_rather_than_silently_never_firing(): void
    {
        // `airlineCode` reads as null on every context, the comparison fails, and the
        // rule charges nothing. That is indistinguishable from a rule nobody wrote.
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'product' => 'flight', 'matchers' => '{"airlineCode": "PR"}',
            ]))
            ->assertSessionHasErrors('matchers');
    }

    public function test_a_matcher_key_from_the_wrong_product_is_refused(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'product' => 'hotel', 'matchers' => '{"airline": "PR"}',
            ]))
            ->assertSessionHasErrors('matchers');

        // The same key on a rule that matches every product is allowed: odd, but it
        // simply never fires on a hotel, and refusing it decides a question nobody asked.
        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'product' => '*', 'matchers' => '{"airline": "PR"}',
            ]))
            ->assertSessionHasNoErrors();
    }

    public function test_empty_narrowing_is_stored_as_nothing_rather_than_an_empty_object(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData(['matchers' => '   ']))
            ->assertSessionHasNoErrors();

        $this->assertNull(PricingRule::latest('id')->first()->matchers);
    }

    public function test_a_seasonal_window_reaches_the_rule(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'valid_from' => '2026-12-15', 'valid_to' => '2027-01-05',
            ]))
            ->assertSessionHasNoErrors();

        $rule = PricingRule::latest('id')->first();

        $this->assertSame('2026-12-15', $rule->valid_from->toDateString());
        $this->assertSame('2027-01-05', $rule->valid_to->toDateString());
    }

    public function test_a_window_that_ends_before_it_starts_is_refused(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'valid_from' => '2027-01-05', 'valid_to' => '2026-12-15',
            ]))
            ->assertSessionHasErrors('valid_to');
    }

    public function test_a_percentage_can_be_taken_of_the_base_fare_alone(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'product' => 'flight', 'calc_type' => 'percentage_markup', 'value' => '12',
                'applies_to' => 'base_fare',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('base_fare', PricingRule::latest('id')->first()->applies_to);
    }

    public function test_charging_on_the_base_fare_is_refused_where_there_is_no_base_fare(): void
    {
        // A hotel rate arrives as one number, so `basisFor()` falls back to the whole of
        // it. Safe, but it makes a rule that says it excludes tax exclude nothing — and
        // a rule matching every product would do one on flights and the other on hotels.
        $this->configureRoot();

        foreach (['hotel', '*'] as $product) {
            foreach (['base_fare', 'excl_ancillaries'] as $chargedOn) {
                $this->actingAs($this->editor())
                    ->post(route('admin.pricing.rules.store'), $this->ruleData([
                        'product' => $product, 'applies_to' => $chargedOn,
                    ]))
                    ->assertSessionHasErrors('applies_to');
            }
        }
    }

    public function test_the_whole_supplier_rate_is_chargeable_on_every_product(): void
    {
        $this->configureRoot();

        foreach (['flight', 'hotel', '*'] as $product) {
            $this->actingAs($this->editor())
                ->post(route('admin.pricing.rules.store'), $this->ruleData([
                    'product' => $product, 'applies_to' => 'total',
                ]))
                ->assertSessionHasNoErrors();
        }
    }

    public function test_the_form_says_why_a_charged_on_option_is_unavailable(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            ->assertSee('Base fare only, before tax')
            // The same phrasing the Type select uses for its own restrictions.
            ->assertSeeInOrder(['Base fare only, before tax', 'flights only'], false);
    }

    // ------------------------------------------------------------- tier tables ----

    public function test_a_tier_table_is_saved_against_the_rule(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'calc_type' => 'tiered',
                'params' => $this->bands([['10000', 'fixed', '800'], ['', 'percentage_markup', '10']]),
            ]))
            ->assertSessionHasNoErrors();

        $rule = PricingRule::firstOrFail();

        $this->assertSame('tiered', $rule->calc_type->value);
        $this->assertCount(2, $rule->params['bands']);
        $this->assertSame('whole', $rule->params['mode']);
        // A tiered rule keeps its numbers in its bands, and `value` is NOT NULL.
        $this->assertSame('0.0000', $rule->value);
    }

    public function test_a_tier_table_that_pays_less_on_a_dearer_fare_is_refused(): void
    {
        // The one that catches people: 12% of 10,000 is 1,200 and 8% of 10,001 is 800.
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'calc_type' => 'tiered',
                'params' => $this->bands([['10000', 'percentage_markup', '12'], ['', 'percentage_markup', '8']]),
            ]))
            ->assertSessionHasErrors('params');

        $this->assertDatabaseCount('pricing_rules', 0);
    }

    public function test_the_same_table_is_accepted_once_it_is_charged_by_slice(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'calc_type' => 'tiered',
                'params' => $this->bands(
                    [['10000', 'percentage_markup', '12'], ['', 'percentage_markup', '8']],
                    'marginal',
                ),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('marginal', PricingRule::firstOrFail()->params['mode']);
    }

    public function test_a_tiered_rule_needs_a_table(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData(['calc_type' => 'tiered']))
            ->assertSessionHasErrors('params');
    }

    public function test_the_blank_row_at_the_bottom_of_the_editor_is_not_a_band(): void
    {
        // The editor keeps an empty row to type into. Counting it would fail every table
        // for a band nobody has written yet.
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'calc_type' => 'tiered',
                'params' => $this->bands([
                    ['10000', 'fixed', '800'],
                    ['', 'percentage_markup', '10'],
                    ['', 'fixed', ''],
                ]),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertCount(2, PricingRule::firstOrFail()->params['bands']);
    }

    public function test_a_rule_that_is_not_tiered_keeps_no_table(): void
    {
        // The editor posts its rows whatever the type. A stored table nothing computes is
        // exactly what somebody later blames a wrong price on.
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'calc_type' => 'percentage_markup',
                'value' => '10',
                'params' => $this->bands([['10000', 'fixed', '800'], ['', 'percentage_markup', '10']]),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull(PricingRule::firstOrFail()->params);
    }

    public function test_the_form_carries_a_band_editor_for_a_tier_table(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            // Shown only for the one type that has a table.
            ->assertSee('x-show="calcType === \'tiered\'"', false)
            ->assertSee('name="params[mode]"', false)
            ->assertSee('params[bands][', false)
            // Both modes, each with the sentence that explains it.
            ->assertSee('The band the fare lands in charges the whole fare')
            ->assertSee('Each band charges only its own slice of the fare')
            // And the amount box stands down for the types that have no amount.
            ->assertSee(':required="hasAmount"', false);
    }

    public function test_the_rule_list_prints_the_table_rather_than_one_number(): void
    {
        $this->configureRoot();

        PricingRule::factory()
            ->for(PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id]), 'strategy')
            ->tiered([[10000, CalcType::Fixed, 800], [null, CalcType::PercentageMarkup, 10]])
            ->create();

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            ->assertSee('Tiered: 800.00 / 10%');
    }

    public function test_a_table_read_per_passenger_needs_a_flight(): void
    {
        // The unit belongs to the product exactly as it does for the per-passenger type:
        // two adults in one double room pay one room rate.
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'product' => 'hotel',
                'calc_type' => 'tiered',
                'params' => $this->bands(
                    [['10000', 'fixed', '800'], ['', 'percentage_markup', '10']],
                    'whole',
                    'passenger',
                ),
            ]))
            ->assertSessionHasErrors('params.bands_on');

        $this->assertDatabaseCount('pricing_rules', 0);
    }

    public function test_a_flight_table_may_be_read_per_passenger(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'product' => 'flight',
                'calc_type' => 'tiered',
                'params' => $this->bands(
                    [['10000', 'fixed', '800'], ['', 'percentage_markup', '10']],
                    'whole',
                    'passenger',
                ),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('passenger', PricingRule::firstOrFail()->params['bands_on']);
    }

    public function test_a_table_that_does_not_say_what_it_reads_reads_the_whole_booking(): void
    {
        // The form always posts it. A caller that did not name a unit did not ask for its
        // fare to be divided, so the stored table says so out loud rather than defaulting
        // later against a booking already priced.
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'calc_type' => 'tiered',
                'params' => ['bands' => [
                    ['up_to' => '10000', 'calc_type' => 'fixed', 'value' => '800'],
                    ['up_to' => '', 'calc_type' => 'percentage_markup', 'value' => '10'],
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('booking', PricingRule::firstOrFail()->params['bands_on']);
    }

    public function test_the_editor_asks_what_the_bands_read(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            ->assertSee('name="params[bands_on]"', false)
            ->assertSee('Each passenger&#039;s share — flights only', false)
            ->assertSee('Each room-night&#039;s share — hotels only', false)
            // Gated by product like Type is, and starting where the product says.
            ->assertSee('in unitByProduct[product]', false)
            ->assertSee('unit = unitDefaults[product]', false);
    }

    // ---------------------------------------------------- service lines ----

    private function officeStrategy(): PricingStrategy
    {
        return PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id]);
    }

    private function handlingFeeRule(PricingStrategy $strategy, string $scope, array $bands, string $note): PricingRule
    {
        return PricingRule::factory()->for($strategy, 'strategy')
            ->forProduct('flight')->scoped($scope)
            ->tiered($bands, TierMode::Whole, TierUnit::Passenger)
            ->create(['description' => $note]);
    }

    public function test_rules_are_grouped_by_the_line_of_business_they_price(): void
    {
        $this->configureRoot();
        $strategy = $this->officeStrategy();

        $this->handlingFeeRule($strategy, 'domestic', [[10000, CalcType::Fixed, 300], [null, CalcType::Fixed, 500]], 'Domestic handling');
        $this->handlingFeeRule($strategy, 'international', [[20000, CalcType::Fixed, 750], [null, CalcType::Fixed, 1500]], 'International handling');
        PricingRule::factory()->for($strategy, 'strategy')->percentage(5)->forProduct('hotel')
            ->create(['description' => 'Hotel base markup']);

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            // Flights before hotels, domestic before international.
            ->assertSeeInOrder(['Domestic Flight', 'International Flight', 'Hotel'])
            ->assertSee('Domestic handling')
            ->assertSee('Hotel base markup');
    }

    public function test_a_service_line_says_how_many_of_its_rules_a_booking_pays(): void
    {
        // Every rule that matches is charged, and a line holding three of them is exactly
        // where somebody forgets that.
        $this->configureRoot();
        $strategy = $this->officeStrategy();

        PricingRule::factory(2)->for($strategy, 'strategy')->fixed(100)->forProduct('flight')->scoped('domestic')->create();

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            ->assertSee('2 rules')
            ->assertSee('a booking they all match pays every one');
    }

    public function test_a_tier_table_is_shown_as_a_rate_sheet(): void
    {
        // Folded into "Tiered: 300.00 / 500.00" a table cannot be checked against the one
        // it was copied from, which is the only thing anybody wants to do with it.
        $this->configureRoot();

        $this->handlingFeeRule(
            $this->officeStrategy(),
            'domestic',
            [[10000, CalcType::Fixed, 300], [30000, CalcType::Fixed, 500], [null, CalcType::Fixed, 1000]],
            'Domestic handling',
        );

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            ->assertSeeInOrder(['Class 1', '0.00', '10,000.00', '300.00'])
            // The lower bound is derived from the band below, a centavo above its limit.
            ->assertSeeInOrder(['Class 2', '10,000.01', '30,000.00', '500.00'])
            ->assertSeeInOrder(['Class 3', '30,000.01', 'No limit', '1,000.00'])
            // How the table is read, which the rows alone do not say.
            ->assertSee('Read at each passenger&#039;s share', false)
            ->assertSee('one band charging the whole amount', false);
    }

    public function test_each_line_can_prefill_the_one_add_form(): void
    {
        // One form and one set of validation, rather than a form per line to keep in step.
        $this->configureRoot();
        $this->handlingFeeRule($this->officeStrategy(), 'domestic', [[10000, CalcType::Fixed, 300], [null, CalcType::Fixed, 500]], 'Domestic');

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            ->assertSee('id="add-rule"', false)
            ->assertSee('$dispatch(\'pricing-line\'', false)
            ->assertSee('x-on:pricing-line.window', false);
    }

    // ------------------------------------------------------------ editing a rule ----

    public function test_the_edit_form_is_filled_in_from_the_rule(): void
    {
        $this->configureRoot();

        $rule = PricingRule::factory()->for($this->officeStrategy(), 'strategy')
            ->percentage('12.5')->forProduct('flight')->scoped('international')
            ->create([
                'description' => 'International base markup',
                'min_markup' => '500.00',
                'max_markup' => '3000.00',
                'applies_to' => 'base_fare',
                'matchers' => ['airline' => ['PR', '5J']],
                'valid_from' => '2026-12-01',
                'valid_to' => '2027-01-15',
            ]);

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.rules.edit', $rule))
            ->assertOk()
            ->assertSee('International base markup')
            ->assertSee('value="12.5"', false)
            ->assertSee('value="500.00"', false)
            ->assertSee('value="3000.00"', false)
            ->assertSee('value="2026-12-01"', false)
            ->assertSee('value="2027-01-15"', false)
            ->assertSee('{&quot;airline&quot;:[&quot;PR&quot;,&quot;5J&quot;]}', false)
            ->assertSee('selected>Percentage markup', false)
            ->assertSee('selected>International', false);
    }

    public function test_a_tier_table_comes_back_into_the_band_editor(): void
    {
        // A table you can only delete and retype is not a table you can edit.
        $this->configureRoot();

        $rule = $this->handlingFeeRule(
            $this->officeStrategy(),
            'domestic',
            [[10000, CalcType::Fixed, 300], [null, CalcType::Fixed, 500]],
            'Domestic handling',
        );

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.rules.edit', $rule))
            ->assertOk()
            ->assertSee('10000', false)
            ->assertSee('selected>Tiered by amount', false)
            // The mode and the unit come back too, not just the rows.
            ->assertSee('selected>The band the fare lands in charges the whole fare', false)
            ->assertSee('passenger', false);
    }

    public function test_saving_an_edit_changes_the_rule(): void
    {
        $this->configureRoot();

        $rule = PricingRule::factory()->for($this->officeStrategy(), 'strategy')->fixed(500)->create();
        $version = $rule->version;

        $this->actingAs($this->editor())
            ->put(route('admin.pricing.rules.update', $rule), $this->ruleData([
                'calc_type' => 'percentage_markup', 'value' => '8', 'description' => 'Now a percentage',
            ]))
            ->assertRedirect(route('admin.pricing.index'));

        $rule->refresh();

        $this->assertSame('percentage_markup', $rule->calc_type->value);
        $this->assertSame('8.0000', $rule->value);
        $this->assertSame('Now a percentage', $rule->description);
        // A quote can tell whether the rule moved under it between search and booking.
        $this->assertGreaterThan($version, $rule->version);
    }

    public function test_an_edit_is_held_to_every_rule_the_add_form_is(): void
    {
        $this->configureRoot();

        $rule = PricingRule::factory()->for($this->officeStrategy(), 'strategy')->fixed(500)->create();

        $this->actingAs($this->editor())
            ->put(route('admin.pricing.rules.update', $rule), $this->ruleData([
                'product' => 'hotel', 'calc_type' => 'per_pax', 'value' => '350',
            ]))
            ->assertSessionHasErrors('calc_type');

        $this->assertSame('fixed', $rule->refresh()->calc_type->value);
    }

    public function test_editing_a_paused_rule_leaves_it_paused(): void
    {
        // There is no switch on the screen, so saving must not be one.
        $this->configureRoot();

        $rule = PricingRule::factory()->for($this->officeStrategy(), 'strategy')->fixed(500)
            ->create(['is_active' => false]);

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.rules.edit', $rule))
            ->assertOk()
            ->assertSee('name="is_active" value="0"', false);
    }

    public function test_this_screen_will_not_touch_an_agency_rule(): void
    {
        // `markup.office.edit` says a user may price the level every agency sits on top
        // of. It does not say they may reach into an agency's own strategy — and until
        // the edit form existed, nothing stopped these three routes taking any rule id.
        $this->configureRoot();

        $agency = Agency::factory()->create();
        $theirs = PricingRule::factory()->fixed(200)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $agency->id])->id,
        ]);

        $this->actingAs($this->editor())->get(route('admin.pricing.rules.edit', $theirs))->assertNotFound();
        $this->actingAs($this->editor())->put(route('admin.pricing.rules.update', $theirs), $this->ruleData())->assertNotFound();
        $this->actingAs($this->editor())->delete(route('admin.pricing.rules.destroy', $theirs))->assertNotFound();

        $this->assertSame('200.0000', $theirs->refresh()->value);
    }

    // ------------------------------------------------- supplier, gated by product ----

    public function test_a_supplier_the_product_is_never_bought_from_is_refused(): void
    {
        // A flight always arrives carrying TBO Air, so this rule passes matchesProduct(),
        // fails matchesSupplier(), and charges nothing on every booking there will ever
        // be — while sitting in the list looking live.
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'product' => 'flight', 'supplier' => 'tbohotel',
            ]))
            ->assertSessionHasErrors('supplier');

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'product' => 'hotel', 'supplier' => 'tboair',
            ]))
            ->assertSessionHasErrors('supplier');

        $this->assertDatabaseCount('pricing_rules', 0);
    }

    public function test_the_supplier_a_product_is_bought_from_is_allowed(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'product' => 'flight', 'supplier' => 'tboair',
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData([
                'product' => 'hotel', 'supplier' => 'tbohotel',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('pricing_rules', ['product' => 'flight', 'supplier' => 'tboair']);
        $this->assertDatabaseHas('pricing_rules', ['product' => 'hotel', 'supplier' => 'tbohotel']);
    }

    public function test_any_supplier_stays_available_on_every_product(): void
    {
        // The wildcard is never the wrong answer, so the gate must not narrow it away.
        $this->configureRoot();

        foreach (['*', 'flight', 'hotel'] as $product) {
            $this->actingAs($this->editor())
                ->post(route('admin.pricing.rules.store'), $this->ruleData([
                    'product' => $product, 'supplier' => '',
                ]))
                ->assertSessionHasNoErrors();
        }
    }

    public function test_a_rule_matching_every_product_may_still_name_a_supplier(): void
    {
        // Unlike a per-passenger fee on a hotel, this one is not a lie: "all products
        // from TBO Air" is a roundabout way of saying flights, and it does match them.
        $this->configureRoot();

        foreach (['tboair', 'tbohotel'] as $supplier) {
            $this->actingAs($this->editor())
                ->post(route('admin.pricing.rules.store'), $this->ruleData([
                    'product' => '*', 'supplier' => $supplier,
                ]))
                ->assertSessionHasNoErrors();
        }
    }

    public function test_the_form_says_why_a_supplier_is_unavailable(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            // The same phrasing the Type and Charged-on selects use, on the option
            // itself — "flights only" alone would pass off the Type select below it.
            ->assertSee('TBO Air — flights only', false)
            ->assertSee('TBO Hotel — hotels only', false)
            // And the greying-out is bound to the product, not baked in.
            ->assertSee('in supplierByProduct[product]', false);
    }

    public function test_the_form_offers_the_fields_the_schema_already_carried(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            ->assertSee('name="matchers"', false)
            ->assertSee('name="valid_from"', false)
            ->assertSee('name="valid_to"', false)
            ->assertSee('name="applies_to"', false)
            // ...and no longer smuggles applies_to through a hidden input.
            ->assertDontSee('<input type="hidden" name="applies_to"', false);
    }

    // ------------------------------------------------------------ the teaching ----

    public function test_the_rule_form_explains_each_type_with_its_own_numbers(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            // The explanations...
            ->assertSee('A percentage of the price the customer pays, not of the cost.')
            ->assertSee('The axis a hotel rate actually moves on', false)
            // ...and the arithmetic beside them, computed by the calculators themselves.
            ->assertSee('20% of the 6,250.00 it sells for')
            ->assertSee('200 × 2 rooms × 3 nights', false);
    }

    public function test_the_form_defines_its_own_fields(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            ->assertSee('What each field means')
            ->assertSee('but never less than 500')
            ->assertSee('but never more than 3,000')
            ->assertSee('a December peak-season rule is about the trip, not the purchase.', false)
            ->assertSee('Extra conditions, written as JSON.')
            // The Charged on definitions come off the enum, so they cannot drift from
            // the select and the validator that use the same source.
            ->assertSee(AppliesTo::BaseFare->guidance());
    }

    public function test_an_agency_only_field_is_not_defined_on_a_form_that_lacks_it(): void
    {
        // The agency form posts a blank supplier; defining a field it cannot see would
        // send somebody looking for a box that is not there.
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            ->assertSee("Limits the rule to one supplier's inventory.", false);
    }

    public function test_the_page_explains_how_the_ladder_adds_up(): void
    {
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            ->assertSee('How a price is built')
            ->assertSee('Every rule that matches applies, and they add up.')
            ->assertSee("Every percentage is of the supplier's rate.", false)
            ->assertSee('The selling price is rounded once, at the end.');
    }

    public function test_editing_a_rule_bumps_its_version(): void
    {
        $this->configureRoot();
        $strategy = PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id]);
        // Refreshed because `version` is not fillable — the database default applies on
        // insert and the in-memory instance does not know about it.
        $rule = PricingRule::factory()->fixed(500)->create(['pricing_strategy_id' => $strategy->id])->fresh();

        $this->assertSame(1, $rule->version);

        $this->actingAs($this->editor())
            ->put(route('admin.pricing.rules.update', $rule), $this->ruleData(['value' => '600']))
            ->assertRedirect();

        $this->assertSame(2, $rule->fresh()->version, 'a quote can tell the rule moved under it');
    }

    public function test_removing_a_rule(): void
    {
        $this->configureRoot();
        $strategy = PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id]);
        $rule = PricingRule::factory()->fixed(500)->create(['pricing_strategy_id' => $strategy->id]);

        $this->actingAs($this->editor())
            ->delete(route('admin.pricing.rules.destroy', $rule))
            ->assertRedirect();

        $this->assertDatabaseMissing('pricing_rules', ['id' => $rule->id]);
    }

    public function test_a_rule_change_is_seen_by_a_resolver_that_already_answered(): void
    {
        $this->configureRoot();
        $agency = Agency::factory()->create();
        $resolver = app(StrategyResolver::class);

        // Make it answer once, with no strategy at all, so it has something to hold.
        $this->assertNull($resolver->chain($agency)[1]['strategy']);

        PricingStrategy::factory()->create(['agency_id' => $agency->id]);
        StrategyResolver::forget($agency->id);

        $this->assertNotNull($resolver->chain($agency)[1]['strategy']);
    }

    // ---------------------------------------------------------------- preview ----

    public function test_the_preview_runs_the_real_engine(): void
    {
        $this->configureRoot();
        $agency = Agency::factory()->create(['name' => 'Agency ABC']);

        PricingRule::factory()->fixed(500)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id])->id,
        ]);
        PricingRule::factory()->fixed(200)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $agency->id])->id,
        ]);

        $this->actingAs($this->editor())
            ->postJson(route('admin.pricing.preview'), [
                'agency_id' => $agency->id, 'net' => '5000', 'product' => 'flight', 'scope' => 'domestic',
            ])
            ->assertOk()
            ->assertJson([
                'net' => '5000.00',
                'cost' => '5500.00',
                'sell' => '5700.00',
                'markupTotal' => '700.00',
                'agency' => 'Agency ABC',
            ]);
    }

    public function test_two_rules_at_one_level_are_two_rungs_the_preview_can_tell_apart(): void
    {
        // A level is cumulative, so `level` is NOT unique across the rungs. The preview
        // keyed its list on it and dropped one of every pair; it keys on level + rule
        // now, which is what booking_price_layers is unique on for the same reason.
        $this->configureRoot();
        $agency = Agency::factory()->create(['name' => 'Agency ABC']);
        $strategy = PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id]);

        PricingRule::factory()->fixed(500)->create(['pricing_strategy_id' => $strategy->id]);
        PricingRule::factory()->fixed(200)->create(['pricing_strategy_id' => $strategy->id]);

        $layers = $this->actingAs($this->editor())
            ->postJson(route('admin.pricing.preview'), [
                'agency_id' => $agency->id, 'net' => '5000', 'product' => 'flight', 'scope' => 'domestic',
            ])
            ->assertOk()
            ->json('layers');

        $this->assertCount(2, $layers);
        $this->assertSame([0, 0], array_column($layers, 'level'), 'both rungs are the same level');

        $keys = array_map(fn (array $l): string => $l['level'].':'.$l['ruleId'], $layers);
        $this->assertSame($keys, array_unique($keys), 'and the key the preview uses still separates them');
    }

    public function test_every_rung_arrives_with_its_own_label(): void
    {
        // The browser used to decide this from `calcType` and called everything that was
        // not a percentage "fixed" — so each type added to the engine was mislabelled
        // until somebody noticed. The server says it now.
        $this->configureRoot();
        $agency = Agency::factory()->create(['name' => 'Agency ABC']);
        $strategy = PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id]);

        PricingRule::factory()->perPax(350)->create(['pricing_strategy_id' => $strategy->id, 'product' => 'flight']);
        PricingRule::factory()->margin(20)->create(['pricing_strategy_id' => $strategy->id]);
        PricingRule::factory()->fixed(150)->create(['pricing_strategy_id' => $strategy->id]);

        $layers = $this->actingAs($this->editor())
            ->postJson(route('admin.pricing.preview'), [
                'agency_id' => $agency->id, 'net' => '5000', 'product' => 'flight', 'scope' => 'domestic',
            ])
            ->assertOk()
            ->json('layers');

        $this->assertSame(
            ['350.00 per passenger', '20%', '150.00'],
            array_column($layers, 'label'),
        );
    }

    public function test_the_preview_can_price_more_than_one_passenger(): void
    {
        // Without a head count the preview always sent one, so a per-passenger rule
        // showed its unit price and never what it charges — the only thing anyone opens
        // this panel to find out.
        $this->configureRoot();
        $agency = Agency::factory()->create(['name' => 'Agency ABC']);

        PricingRule::factory()->perPax(350)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id])->id,
            'product' => 'flight',
        ]);

        $this->actingAs($this->editor())
            ->postJson(route('admin.pricing.preview'), [
                'agency_id' => $agency->id, 'net' => '5000', 'product' => 'flight', 'scope' => 'domestic', 'pax' => 2,
            ])
            ->assertOk()
            ->assertJson(['sell' => '5700.00', 'markupTotal' => '700.00']);
    }

    public function test_the_preview_can_price_more_than_one_room_night(): void
    {
        $this->configureRoot();
        $agency = Agency::factory()->create(['name' => 'Agency ABC']);

        PricingRule::factory()->perRoomNight(200)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id])->id,
            'product' => 'hotel',
        ]);

        $this->actingAs($this->editor())
            ->postJson(route('admin.pricing.preview'), [
                'agency_id' => $agency->id, 'net' => '5000', 'product' => 'hotel', 'scope' => 'domestic',
                'rooms' => 2, 'nights' => 3,
            ])
            ->assertOk()
            ->assertJson(['sell' => '6200.00', 'markupTotal' => '1200.00']);
    }

    public function test_the_preview_still_prices_when_no_counts_are_sent(): void
    {
        // Nullable and defaulted to one, so nothing that predates the counts breaks.
        $this->configureRoot();
        $agency = Agency::factory()->create(['name' => 'Agency ABC']);

        PricingRule::factory()->perPax(350)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id])->id,
            'product' => 'flight',
        ]);

        $this->actingAs($this->editor())
            ->postJson(route('admin.pricing.preview'), [
                'agency_id' => $agency->id, 'net' => '5000', 'product' => 'flight', 'scope' => 'domestic',
            ])
            ->assertOk()
            ->assertJson(['sell' => '5350.00']);
    }

    public function test_the_preview_refuses_a_head_count_the_booking_forms_would_refuse(): void
    {
        $this->configureRoot();
        $agency = Agency::factory()->create(['name' => 'Agency ABC']);

        $this->actingAs($this->editor())
            ->postJson(route('admin.pricing.preview'), [
                'agency_id' => $agency->id, 'net' => '5000', 'product' => 'flight', 'scope' => 'domestic', 'pax' => 40,
            ])
            ->assertStatus(422);
    }

    // -------------------------------------------------------- the hierarchy ----

    public function test_the_hierarchy_prices_its_sample_at_the_counts_it_is_given(): void
    {
        // Fixed at one of each, this table read a per-unit rule low — the same gap the
        // preview panel had, on a screen that exists to compare partners.
        $this->configureRoot();
        Agency::factory()->create(['name' => 'Agency ABC']);

        PricingRule::factory()->perPax(350)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id])->id,
            'product' => 'flight',
        ]);

        $ladder = $this->actingAs($this->editor())
            ->get(route('admin.pricing.index', ['sample_net' => '5000', 'sample_pax' => 3]))
            ->assertOk()
            ->viewData('ladder');

        $row = $ladder->firstWhere(fn (array $r): bool => $r['agency']->name === 'Agency ABC');

        $this->assertSame('6050.00', (string) $row['sell'], '3 x 350 on top of 5,000');
    }

    public function test_the_hierarchy_can_be_sampled_on_a_hotel_stay(): void
    {
        $this->configureRoot();
        Agency::factory()->create(['name' => 'Agency ABC']);

        PricingRule::factory()->perRoomNight(200)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id])->id,
            'product' => 'hotel',
        ]);

        $ladder = $this->actingAs($this->editor())
            ->get(route('admin.pricing.index', [
                'sample_net' => '5000', 'sample_product' => 'hotel', 'sample_rooms' => 2, 'sample_nights' => 3,
            ]))
            ->assertOk()
            ->viewData('ladder');

        $row = $ladder->firstWhere(fn (array $r): bool => $r['agency']->name === 'Agency ABC');

        $this->assertSame('6200.00', (string) $row['sell'], 'six room-nights at 200');
    }

    public function test_the_hierarchy_says_which_fare_it_priced(): void
    {
        // The table is only readable if the sample above it is stated — two agencies
        // 700 apart means nothing without knowing it was three passengers.
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.index', ['sample_pax' => 2]))
            ->assertOk()
            ->assertSee('2 passengers');

        $this->actingAs($this->editor())
            ->get(route('admin.pricing.index', ['sample_product' => 'hotel', 'sample_rooms' => 2, 'sample_nights' => 3]))
            ->assertOk()
            ->assertSee('2 rooms for 3 nights');
    }

    public function test_the_hierarchy_falls_back_to_the_configured_sample(): void
    {
        $this->configureRoot();
        Agency::factory()->create(['name' => 'Agency ABC']);

        $sample = $this->actingAs($this->editor())
            ->get(route('admin.pricing.index'))
            ->assertOk()
            ->viewData('sample');

        $this->assertSame((string) config('pricing.preview_net', 5000), $sample['net']);
        $this->assertSame(BookingProduct::Flight, $sample['product']);
        $this->assertSame(1, $sample['pax']);
    }

    public function test_a_nonsense_sample_is_clamped_rather_than_rejected(): void
    {
        // Validating here would redirect back to this page — which is this page, with
        // the same query string. Out-of-range input becomes the nearest sensible value.
        $this->configureRoot();

        $sample = $this->actingAs($this->editor())
            ->get(route('admin.pricing.index', [
                'sample_net' => 'lots', 'sample_product' => 'submarine',
                'sample_pax' => 400, 'sample_rooms' => 0, 'sample_nights' => -3,
            ]))
            ->assertOk()
            ->viewData('sample');

        $this->assertSame((string) config('pricing.preview_net', 5000), $sample['net']);
        $this->assertSame(BookingProduct::Flight, $sample['product']);
        $this->assertSame(9, $sample['pax'], 'held to the search form ceiling');
        $this->assertSame(1, $sample['rooms']);
        $this->assertSame(1, $sample['nights']);
    }

    public function test_the_preview_explains_a_missing_pricing_root_rather_than_failing(): void
    {
        $agency = Agency::factory()->create();

        $this->actingAs($this->editor())
            ->postJson(route('admin.pricing.preview'), [
                'agency_id' => $agency->id, 'net' => '5000', 'product' => 'flight', 'scope' => 'domestic',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'No Main Office is configured'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    /**
     * Band rows shaped the way the editor posts them — strings throughout, blank for the
     * open-ended limit.
     *
     * @param  array<int, array{0: string, 1: string, 2: string}>  $bands
     * @return array<string, mixed>
     */
    private function bands(array $bands, string $mode = 'whole', string $unit = 'booking'): array
    {
        return ['mode' => $mode, 'bands_on' => $unit, 'bands' => array_map(fn (array $band): array => [
            'up_to' => $band[0], 'calc_type' => $band[1], 'value' => $band[2],
        ], $bands)];
    }

    private function ruleData(array $overrides = []): array
    {
        return array_merge([
            'product' => '*',
            'supplier' => '',
            'scope' => 'any',
            'calc_type' => 'fixed',
            'value' => '500',
            'basis' => 'net',
            'applies_to' => 'total',
            'rounding' => 'none',
            'priority' => 100,
            'is_active' => 1,
        ], $overrides);
    }
}
