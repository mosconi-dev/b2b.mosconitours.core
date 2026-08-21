<?php

namespace Tests\Feature\Pricing;

use App\Http\Requests\Admin\StoreAgencyPricingRuleRequest;
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
use Illuminate\Support\Facades\DB;
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
            'basis' => 'net',
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

    /**
     * The agency form has no order field, so the request must supply one.
     *
     * Order only moves a total for a rule that compounds, and every agency rule is
     * pinned to the supplier net — so the field is noise on that screen and the column
     * is defaulted server-side instead.
     */
    public function test_an_agency_rule_needs_no_order_from_the_form(): void
    {
        $user = $this->memberOf($this->agency, ['admin.access', 'agency.view', 'markup.view', 'markup.edit']);

        $data = $this->ruleData();
        unset($data['priority']);

        $this->actingAs($user)
            ->post(route('admin.agencies.markup.rules.store', $this->agency), $data)
            ->assertRedirect();

        $this->assertDatabaseHas('pricing_rules', [
            'value' => '200.0000',
            'priority' => StoreAgencyPricingRuleRequest::DEFAULT_PRIORITY,
        ]);
    }

    /**
     * The note is optional, is kept, and travels into the snapshot.
     *
     * Rules accumulate, so a strategy ends up holding several. What each adds is obvious
     * from its figures; why it was added is not, and that is the thing anyone needs
     * months later when deciding whether it can go.
     */
    public function test_an_agency_can_bound_its_own_percentage(): void
    {
        // "10%, at least 500" — validated and honoured since the engine shipped, and
        // until now reachable only from the office screen.
        $user = $this->memberOf($this->agency, ['admin.access', 'agency.view', 'markup.view', 'markup.edit']);

        $this->actingAs($user)
            ->post(route('admin.agencies.markup.rules.store', $this->agency), $this->ruleData([
                'calc_type' => 'percentage_markup', 'value' => '10', 'min_markup' => '500', 'max_markup' => '3000',
            ]))
            ->assertSessionHasNoErrors();

        $rule = PricingRule::latest('id')->first();

        $this->assertSame('500.00', $rule->min_markup);
        $this->assertSame('3000.00', $rule->max_markup);
    }

    public function test_the_agency_form_offers_the_fields_the_office_form_does(): void
    {
        $user = $this->memberOf($this->agency, ['admin.access', 'agency.view', 'markup.view', 'markup.edit']);

        $response = $this->actingAs($user)
            ->get(route('admin.agencies.show', ['agency' => $this->agency, 'tab' => 'markup']))
            ->assertOk();

        foreach (['min_markup', 'max_markup', 'matchers', 'valid_from', 'valid_to', 'applies_to'] as $field) {
            $response->assertSee('name="'.$field.'"', false);
        }

        $response->assertDontSee('<input type="hidden" name="applies_to"', false);
    }

    public function test_an_agency_is_held_to_the_same_product_gate_as_the_office(): void
    {
        // StoreAgencyPricingRuleRequest extends the office request precisely so a rule
        // cannot be shaped differently depending on which screen it was written on.
        $user = $this->memberOf($this->agency, ['admin.access', 'agency.view', 'markup.view', 'markup.edit']);

        $this->actingAs($user)
            ->post(route('admin.agencies.markup.rules.store', $this->agency), $this->ruleData([
                'product' => 'hotel', 'calc_type' => 'per_pax', 'value' => '150',
            ]))
            ->assertSessionHasErrors('calc_type');

        $this->actingAs($user)
            ->post(route('admin.agencies.markup.rules.store', $this->agency), $this->ruleData([
                'product' => 'hotel', 'calc_type' => 'per_room_night', 'value' => '150',
            ]))
            ->assertSessionHasNoErrors();
    }

    public function test_a_rule_keeps_the_note_explaining_why_it_exists(): void
    {
        $user = $this->memberOf($this->agency, ['admin.access', 'agency.view', 'markup.view', 'markup.edit']);

        $this->actingAs($user)
            ->post(route('admin.agencies.markup.rules.store', $this->agency), $this->ruleData([
                'description' => 'Peak season surcharge agreed with the office',
            ]))
            ->assertRedirect();

        $rule = PricingRule::latest('id')->first();

        $this->assertSame('Peak season surcharge agreed with the office', $rule->description);
        $this->assertSame(
            'Peak season surcharge agreed with the office',
            $rule->snapshot()['description'],
            'so a past booking can still account for itself once the rule is deleted',
        );
    }

    public function test_an_empty_note_is_stored_as_nothing_rather_than_an_empty_string(): void
    {
        $user = $this->memberOf($this->agency, ['admin.access', 'agency.view', 'markup.view', 'markup.edit']);

        $this->actingAs($user)
            ->post(route('admin.agencies.markup.rules.store', $this->agency), $this->ruleData(['description' => '']))
            ->assertRedirect();

        $this->assertNull(PricingRule::latest('id')->first()->description);
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

    public function test_an_agency_percentage_is_pinned_to_the_supplier_net(): void
    {
        // An agency percentage is of the original supplier price, so the two levels add
        // rather than compound. A hand-made request asking for `running` is refused the
        // same way the form is, because a hidden field is not enforcement.
        $user = $this->memberOf($this->agency, ['admin.access', 'agency.view', 'markup.view', 'markup.edit']);

        $this->actingAs($user)
            ->post(route('admin.agencies.markup.rules.store', $this->agency), $this->ruleData([
                'calc_type' => 'percentage_markup', 'value' => '10', 'basis' => 'running',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('pricing_rules', ['calc_type' => 'percentage_markup', 'basis' => 'net']);
    }

    public function test_a_fixed_rule_is_pinned_to_net_as_well(): void
    {
        // Fixed rules ignore the basis when they compute, but they are stored on net
        // like everything else so the invariant reads the same on every row: no rule in
        // this system compounds.
        $user = $this->memberOf($this->agency, ['admin.access', 'agency.view', 'markup.view', 'markup.edit']);

        $this->actingAs($user)
            ->post(route('admin.agencies.markup.rules.store', $this->agency), $this->ruleData([
                'calc_type' => 'fixed', 'basis' => 'running',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('pricing_rules', ['calc_type' => 'fixed', 'basis' => 'net']);
    }

    // ---------------------------------------------------------------- preview ----

    /**
     * The preview answers "what does MY rule add to a rate I was quoted?".
     *
     * It runs the whole chain, because that is the one engine, but only the agency's own
     * rung comes back. Returning the chain's real cost of ₱5,500 against the ₱5,000 they
     * typed would give up the Main Office's ₱500 as the difference — the amount that
     * must stay opaque.
     */
    public function test_an_agency_rung_carries_its_own_label_and_no_more_than_before(): void
    {
        // Its panel used to phrase this in JavaScript and called anything that was not a
        // percentage "a flat X" — wrong for a per-passenger fee the moment that type
        // existed. The label is derived from calcType and value, both of which this
        // payload already carried, so it discloses nothing new.
        PricingRule::factory()->perPax(200)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->agency->id])->id,
            'product' => 'flight',
        ]);

        $user = $this->memberOf($this->agency, ['admin.access', 'agency.view', 'markup.view']);

        $response = $this->actingAs($user)
            ->postJson(route('admin.agencies.markup.preview', $this->agency), [
                'net' => '5000', 'product' => 'flight', 'scope' => 'domestic',
            ])
            ->assertOk();

        $this->assertSame('200.00 per passenger', $response->json('ownLayers.0.label'));

        // The boundary is unmoved: still no supplier net and no basis amount.
        $this->assertNull($response->json('net'));
        $this->assertNull($response->json('ownLayers.0.basisAmount'));
    }

    public function test_the_agency_preview_shows_only_its_own_rung(): void
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
            ->assertJson(['cost' => '5000.00', 'markup' => '200.00', 'sell' => '5200.00']);

        $this->assertNull($response->json('net'));
        $this->assertNull($response->json('layers'));

        // Neither the Main Office's rung nor the real selling price it produces.
        $json = $response->getContent();
        $this->assertStringNotContainsString('5500.00', $json);
        $this->assertStringNotContainsString('5700.00', $json);
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

    /**
     * The monthly trend must build ONE period column.
     *
     * `selectRaw` appends rather than replaces, so adding a MySQL expression on top of a
     * SQLite one produced a query carrying `strftime` AND `date_format`. MySQL rejected
     * it outright, while the suite — which runs only on SQLite — passed. Assert the
     * shape, because CI cannot run the driver that broke.
     */
    public function test_the_monthly_trend_builds_one_period_expression(): void
    {
        Booking::factory()->create(['agency_id' => $this->agency->id])->priceLayers()->create([
            'level' => BookingPriceLayer::AGENCY,
            'agency_id' => $this->agency->id,
            'rule_snapshot' => ['calc_type' => 'fixed', 'value' => '200.00'],
            'basis_amount' => '5000.00',
            'markup_amount' => '200.00',
            'running_total' => '5700.00',
            'created_at' => now(),
        ]);

        DB::enableQueryLog();
        $rows = app(MarginReport::class)->monthly($this->agency->id);
        $sql = DB::getQueryLog()[0]['query'];
        DB::disableQueryLog();

        $this->assertSame(1, substr_count($sql, 'as period'), "one period column, got: {$sql}");
        $this->assertSame(1, substr_count($sql, 'as margin'));
        $this->assertCount(1, $rows);
        $this->assertSame('200.00', (string) $rows[0]->margin);
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
