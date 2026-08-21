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
