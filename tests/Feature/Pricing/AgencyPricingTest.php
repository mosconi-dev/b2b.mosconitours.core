<?php

namespace Tests\Feature\Pricing;

use App\Models\Agency;
use App\Models\Booking;
use App\Models\BookingPriceLayer;
use App\Models\PricingRule;
use App\Models\PricingStrategy;
use App\Models\User;
use App\Services\Pricing\MarginReport;
use App\Services\Pricing\StrategyResolver;
use App\Services\Settings\Settings;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Phase 5: an agency configures its own rung, and only its own.
 */
class AgencyPricingTest extends TestCase
{
    use InteractsWithRbac, RefreshDatabase;

    private Agency $mainOffice;

    private Agency $agency;

    private Agency $rival;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->mainOffice = Agency::factory()->create(['name' => 'Main Office']);
        $this->agency = Agency::factory()->create(['name' => 'Agency ABC']);
        $this->rival = Agency::factory()->create(['name' => 'Agency XYZ']);

        app(Settings::class)->set(StrategyResolver::MAIN_OFFICE_SETTING, (string) $this->mainOffice->id);
    }

    private function memberOf(Agency $agency, array $permissions): User
    {
        $user = $this->userWith($permissions);
        $user->update(['agency_id' => $agency->id]);

        return $user->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleData(array $overrides = []): array
    {
        return array_merge([
            'product' => '*',
            'supplier' => '',
            'scope' => 'any',
            'calc_type' => 'fixed',
            'value' => '200',
            'basis' => 'running',
            'applies_to' => 'total',
            'rounding' => 'none',
            'priority' => 100,
            'is_active' => 1,
        ], $overrides);
    }

    // ------------------------------------------------------------------ scope ----

    public function test_an_agency_can_add_its_own_rule(): void
    {
        $user = $this->memberOf($this->agency, ['admin.access', 'agency.view', 'markup.view', 'markup.edit']);

        $this->actingAs($user)
            ->post(route('admin.agencies.markup.rules.store', $this->agency), $this->ruleData())
            ->assertRedirect();

        $this->assertDatabaseHas('pricing_rules', ['value' => '200.0000']);
    }

    public function test_markup_edit_in_one_agency_does_nothing_in_another(): void
    {
        // The whole point of the policy: a partner must not be able to reprice a rival.
        $user = $this->memberOf($this->agency, ['admin.access', 'agency.view', 'markup.view', 'markup.edit']);

        $this->actingAs($user)
            ->post(route('admin.agencies.markup.rules.store', $this->rival), $this->ruleData())
            ->assertForbidden();

        $this->assertDatabaseCount('pricing_rules', 0);
    }

    public function test_a_user_without_markup_edit_cannot_configure_pricing(): void
    {
        $user = $this->memberOf($this->agency, ['admin.access', 'agency.view', 'markup.view']);

        $this->actingAs($user)
            ->post(route('admin.agencies.markup.rules.store', $this->agency), $this->ruleData())
            ->assertForbidden();
    }

    public function test_the_pricing_root_cannot_be_edited_through_the_agency_path(): void
    {
        // It is the level everyone else builds on, so there is exactly one route to it
        // and one permission guarding that route.
        $staff = $this->userWith(['admin.access', 'agency.view', 'markup.view', 'markup.edit']);

        $this->actingAs($staff)
            ->post(route('admin.agencies.markup.rules.store', $this->mainOffice), $this->ruleData())
            ->assertForbidden();
    }

    public function test_a_rule_reached_through_the_wrong_agency_is_not_found(): void
    {
        // Whether that rule exists is not this agency's business, so 404 rather than 403.
        $rule = PricingRule::factory()->fixed(200)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->rival->id])->id,
        ]);

        $user = $this->memberOf($this->agency, ['admin.access', 'agency.view', 'markup.view', 'markup.edit']);

        $this->actingAs($user)
            ->delete(route('admin.agencies.markup.rules.destroy', [$this->agency, $rule]))
            ->assertNotFound();
    }

    // ---------------------------------------------------------- the D12 guard ----

    public function test_an_agency_percentage_is_forced_onto_its_own_cost(): void
    {
        // A percentage of NET would let this agency divide its own markup by the rate
        // and read off the supplier price. Working from the running total means the
        // percentage is of a figure it already knows.
        $user = $this->memberOf($this->agency, ['admin.access', 'agency.view', 'markup.view', 'markup.edit']);

        $this->actingAs($user)
            ->post(route('admin.agencies.markup.rules.store', $this->agency), $this->ruleData([
                'calc_type' => 'percentage_markup', 'value' => '10', 'basis' => 'net',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('pricing_rules', ['calc_type' => 'percentage_markup', 'basis' => 'running']);
    }

    public function test_a_fixed_agency_rule_keeps_whatever_basis_it_was_given(): void
    {
        // A flat 200 says nothing about what the room cost, so the restriction does not
        // apply to it.
        $user = $this->memberOf($this->agency, ['admin.access', 'agency.view', 'markup.view', 'markup.edit']);

        $this->actingAs($user)
            ->post(route('admin.agencies.markup.rules.store', $this->agency), $this->ruleData([
                'calc_type' => 'fixed', 'basis' => 'net',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('pricing_rules', ['calc_type' => 'fixed', 'basis' => 'net']);
    }

    // ---------------------------------------------------------------- preview ----

    public function test_the_agency_preview_shows_cost_markup_and_sell_but_never_net(): void
    {
        PricingRule::factory()->fixed(500)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id])->id,
        ]);
        PricingRule::factory()->fixed(200)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->agency->id])->id,
        ]);

        $user = $this->memberOf($this->agency, ['admin.access', 'agency.view', 'markup.view']);

        $response = $this->actingAs($user)
            ->postJson(route('admin.agencies.markup.preview', $this->agency), [
                'net' => '5000', 'product' => 'flight', 'scope' => 'domestic',
            ])
            ->assertOk()
            ->assertJson(['cost' => '5500.00', 'markup' => '200.00', 'sell' => '5700.00']);

        $this->assertNull($response->json('net'));
        $this->assertNull($response->json('layers'));
    }

    // ------------------------------------------------------------------- tab ----

    public function test_the_markup_tab_appears_only_with_the_permission(): void
    {
        $withOut = $this->memberOf($this->agency, ['admin.access', 'agency.view', 'user.view']);

        $this->actingAs($withOut)
            ->get(route('admin.agencies.show', ['agency' => $this->agency, 'tab' => 'markup']))
            ->assertOk()
            ->assertDontSee('Your rules');

        $withIt = $this->memberOf($this->agency, ['admin.access', 'agency.view', 'markup.view']);

        $this->actingAs($withIt)
            ->get(route('admin.agencies.show', ['agency' => $this->agency, 'tab' => 'markup']))
            ->assertOk()
            ->assertSee('Your rules');
    }

    // ---------------------------------------------------------------- margins ----

    public function test_margin_is_reported_per_agency_from_the_stored_layers(): void
    {
        foreach (['200.00', '350.00'] as $margin) {
            Booking::factory()->create(['agency_id' => $this->agency->id])->priceLayers()->create([
                'level' => BookingPriceLayer::AGENCY,
                'agency_id' => $this->agency->id,
                'rule_snapshot' => ['calc_type' => 'fixed', 'value' => $margin],
                'basis_amount' => '5000.00',
                'markup_amount' => $margin,
                'running_total' => '5700.00',
                'created_at' => now(),
            ]);
        }

        $staff = User::factory()->create(['agency_id' => null]);
        $rows = app(MarginReport::class)->byAgency($staff);

        $this->assertCount(1, $rows);
        $this->assertSame('550.00', (string) $rows[0]->margin);
        $this->assertSame(2, $rows[0]->bookings);
    }

    public function test_an_agency_sees_only_its_own_margin(): void
    {
        foreach ([$this->agency, $this->rival] as $agency) {
            Booking::factory()->create(['agency_id' => $agency->id])->priceLayers()->create([
                'level' => BookingPriceLayer::AGENCY,
                'agency_id' => $agency->id,
                'rule_snapshot' => [],
                'basis_amount' => '5000.00',
                'markup_amount' => '200.00',
                'running_total' => '5700.00',
                'created_at' => now(),
            ]);
        }

        $user = $this->memberOf($this->agency, ['admin.access', 'markup.view']);
        $rows = app(MarginReport::class)->byAgency($user);

        $this->assertCount(1, $rows, 'a rival’s margin is not this agency’s business');
        $this->assertSame($this->agency->id, $rows[0]->agency->id);
    }

    public function test_the_platform_take_is_the_office_rung_across_every_partner(): void
    {
        foreach ([$this->agency, $this->rival] as $agency) {
            Booking::factory()->create(['agency_id' => $agency->id])->priceLayers()->create([
                'level' => BookingPriceLayer::MAIN_OFFICE,
                'agency_id' => $this->mainOffice->id,
                'rule_snapshot' => [],
                'basis_amount' => '5000.00',
                'markup_amount' => '500.00',
                'running_total' => '5500.00',
                'created_at' => now(),
            ]);
        }

        $this->assertSame('1000.00', (string) app(MarginReport::class)->platformTake());
    }
}
