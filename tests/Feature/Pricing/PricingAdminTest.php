<?php

namespace Tests\Feature\Pricing;

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
        // Better refused here than discovered mid-quote on a live search.
        $this->configureRoot();

        $this->actingAs($this->editor())
            ->post(route('admin.pricing.rules.store'), $this->ruleData(['calc_type' => 'tiered']))
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
                'calc_type' => 'percentage_markup', 'value' => '12', 'applies_to' => 'base_fare',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('base_fare', PricingRule::latest('id')->first()->applies_to);
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
