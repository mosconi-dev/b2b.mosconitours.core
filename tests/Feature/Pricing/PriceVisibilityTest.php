<?php

namespace Tests\Feature\Pricing;

use App\Enums\BookingProduct;
use App\Enums\Supplier;
use App\Enums\TravelScope;
use App\Models\Agency;
use App\Models\PricingRule;
use App\Models\PricingStrategy;
use App\Models\User;
use App\Services\Pricing\NetPrice;
use App\Services\Pricing\PriceBreakdown;
use App\Services\Pricing\PricingContext;
use App\Services\Pricing\PricingEngine;
use App\Services\Pricing\StrategyResolver;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Price visibility is a security boundary, not a presentation choice.
 *
 * Search results reach the browser as JSON, so whatever survives forViewer() is what a
 * viewer can read in devtools. An agency must never receive the supplier net or the
 * Main Office's margin as a separate figure.
 */
class PriceVisibilityTest extends TestCase
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

        PricingRule::factory()->fixed(500)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id])->id,
        ]);
        PricingRule::factory()->fixed(200)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->agency->id])->id,
        ]);
    }

    private function breakdown(): PriceBreakdown
    {
        return app(PricingEngine::class)->quote(
            new PricingContext(
                product: BookingProduct::Flight,
                supplier: Supplier::TboAir,
                scope: TravelScope::Domestic,
                net: NetPrice::of(5000),
            ),
            $this->agency,
        );
    }

    public function test_an_agency_user_sees_cost_own_markup_and_sell_and_nothing_else(): void
    {
        $user = User::factory()->create(['agency_id' => $this->agency->id]);

        $payload = $this->breakdown()->forViewer($user);

        $this->assertSame('5500.00', $payload['cost'], 'net + Main Office, as one opaque number');
        $this->assertSame('200.00', $payload['markup'], 'their own margin, which is theirs to see');
        $this->assertSame('5700.00', $payload['sell']);
        $this->assertNull($payload['net'], 'the supplier rate is never theirs');
    }

    public function test_the_upstream_layer_is_absent_from_an_agency_payload(): void
    {
        $user = User::factory()->create(['agency_id' => $this->agency->id]);

        $payload = $this->breakdown()->forViewer($user);
        $json = json_encode($payload);

        $this->assertArrayNotHasKey('layers', $payload);
        $this->assertStringNotContainsString('Main Office', $json);

        // The supplier net must not appear anywhere in the payload, including inside
        // their own layer — `basisAmount` is the net on a net-basis rule, which is how
        // it leaks without anyone noticing.
        $this->assertStringNotContainsString('5000.00', $json);
        $this->assertArrayNotHasKey('basisAmount', $payload['ownLayer']);
        $this->assertArrayNotHasKey('runningTotal', $payload['ownLayer']);

        // Their own rung is the only one present, and describes itself.
        $this->assertSame(1, $payload['ownLayer']['level']);
        $this->assertSame('Agency ABC', $payload['ownLayer']['agencyName']);
        $this->assertSame('200.00', $payload['ownLayer']['markup']);
    }

    public function test_platform_staff_see_the_whole_ladder(): void
    {
        $staff = User::factory()->create(['agency_id' => null]);

        $payload = $this->breakdown()->forViewer($staff);

        $this->assertSame('5000.00', $payload['net']);
        $this->assertCount(2, $payload['layers']);
        $this->assertSame('500.00', $payload['layers'][0]['markup']);
        $this->assertSame('200.00', $payload['layers'][1]['markup']);
    }

    public function test_a_null_viewer_is_treated_as_an_agency_and_shown_the_least(): void
    {
        // Defensive: an unauthenticated path must not fall through to the full ladder.
        $payload = $this->breakdown()->forViewer(null);

        $this->assertNull($payload['net']);
        $this->assertArrayNotHasKey('layers', $payload);
    }

    public function test_a_main_office_member_may_see_the_net(): void
    {
        $user = User::factory()->create(['agency_id' => $this->mainOffice->id]);

        $price = app(PricingEngine::class)->quote(
            new PricingContext(
                product: BookingProduct::Flight,
                supplier: Supplier::TboAir,
                scope: TravelScope::Domestic,
                net: NetPrice::of(5000),
            ),
            $this->mainOffice,
        );

        $payload = $price->forViewer($user);

        // Level 0 has nothing above it, so its cost IS the net — hiding it would hide
        // a number they already own.
        $this->assertSame('5000.00', $payload['net']);
        $this->assertSame('5000.00', $payload['cost']);
        $this->assertSame('500.00', $payload['markup']);
        $this->assertSame('5500.00', $payload['sell']);
    }

    public function test_an_unpriced_breakdown_reveals_nothing_either(): void
    {
        $user = User::factory()->create(['agency_id' => $this->agency->id]);

        $payload = PriceBreakdown::unpriced(NetPrice::of(5000))->forViewer($user);

        $this->assertSame('5000.00', $payload['cost'], 'with no markup, cost is the net');
        $this->assertSame('0.00', $payload['markup']);
        $this->assertSame('5000.00', $payload['sell']);
    }
}
