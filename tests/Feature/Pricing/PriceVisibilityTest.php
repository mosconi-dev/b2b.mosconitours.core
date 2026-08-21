<?php

namespace Tests\Feature\Pricing;

use App\Enums\BookingProduct;
use App\Enums\Supplier;
use App\Enums\TravelScope;
use App\Models\Agency;
use App\Models\PricingRule;
use App\Models\PricingStrategy;
use App\Models\User;
use App\Services\Pricing\AgencyPriceView;
use App\Services\Pricing\Money;
use App\Services\Pricing\NetPrice;
use App\Services\Pricing\PriceBreakdown;
use App\Services\Pricing\PricingContext;
use App\Services\Pricing\PricingEngine;
use App\Services\Pricing\StrategyResolver;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The line between the internal price and the external one.
 *
 * PriceBreakdown is the full ladder and is an audit record. AgencyPriceView is the only
 * shape that may reach an agency. Search results travel to the browser as JSON, so
 * whatever survives the second is what a viewer can read in devtools.
 *
 * The ladder here: a ₱5,000 supplier net, ₱500 from the Main Office, ₱200 from the
 * agency — cost ₱5,700 to the customer, ₱5,500 to the agency.
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

    private function breakdown(?Agency $for = null): PriceBreakdown
    {
        return app(PricingEngine::class)->quote(
            new PricingContext(
                product: BookingProduct::Flight,
                supplier: Supplier::TboAir,
                scope: TravelScope::Domestic,
                net: NetPrice::of(5000),
            ),
            $for ?? $this->agency,
        );
    }

    private function agencyUser(): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id]);
    }

    // ------------------------------------------------------------- real offers ----

    /**
     * On a real offer an agency sees its own position: cost, its margin, the sell price.
     *
     * An agent quoting a customer needs to know what it earns on the fare in front of
     * it. `cost` is deliberately ONE number — net and the Main Office's cut fused, with
     * nothing to separate them.
     */
    public function test_an_agency_offer_carries_its_own_position(): void
    {
        $payload = AgencyPriceView::forOffer($this->breakdown(), $this->agencyUser())->toArray();

        $this->assertSame(['currency', 'cost', 'markup', 'sell', 'ownLayers'], array_keys($payload));
        $this->assertSame('5500.00', $payload['cost'], 'net + the office, as one opaque figure');
        $this->assertSame('200.00', $payload['markup'], 'their own margin');
        $this->assertSame('5700.00', $payload['sell']);
    }

    public function test_the_upstream_rung_never_survives_into_an_agency_offer(): void
    {
        $payload = AgencyPriceView::forOffer($this->breakdown(), $this->agencyUser())->toArray();
        $json = json_encode($payload);

        // The office's 500 must not appear as a figure of its own, nor the net it was
        // taken from, nor any hint of how many levels sit above.
        $this->assertArrayNotHasKey('net', $payload);
        $this->assertArrayNotHasKey('layers', $payload);
        $this->assertStringNotContainsString('5000.00', $json, 'the supplier net');
        $this->assertStringNotContainsString('Main Office', $json);

        // Only their OWN rung comes back, and redacted.
        $this->assertCount(1, $payload['ownLayers']);
        $this->assertSame(1, $payload['ownLayers'][0]['level']);
        $this->assertArrayNotHasKey('basisAmount', $payload['ownLayers'][0]);
        $this->assertArrayNotHasKey('runningTotal', $payload['ownLayers'][0]);
    }

    public function test_a_null_viewer_is_treated_as_an_agency_and_shown_the_least(): void
    {
        // Defensive: an unauthenticated path must not fall through to the full ladder.
        $payload = AgencyPriceView::forOffer($this->breakdown(), null)->toArray();

        $this->assertArrayNotHasKey('net', $payload);
        $this->assertArrayNotHasKey('layers', $payload);
    }

    public function test_an_unpriced_offer_never_labels_the_net_as_such(): void
    {
        // With no rules the sell price IS the net. It may be shown as cost and as sell,
        // because that is genuinely what the agency pays and charges — but never under
        // a key that tells them it is the supplier's own figure.
        $payload = AgencyPriceView::forOffer(
            PriceBreakdown::unpriced(NetPrice::of(5000), bookerLevel: 1),
            $this->agencyUser(),
        )->toArray();

        $this->assertArrayNotHasKey('net', $payload);
        $this->assertSame('5000.00', $payload['sell']);
        $this->assertSame('0.00', $payload['markup']);
        $this->assertSame([], $payload['ownLayers']);
    }

    // ------------------------------------------------------ entitled  viewers ----

    public function test_platform_staff_see_the_whole_ladder(): void
    {
        $staff = User::factory()->create(['agency_id' => null]);

        $payload = AgencyPriceView::forOffer($this->breakdown(), $staff)->toArray();

        $this->assertSame('5000.00', $payload['net']);
        $this->assertCount(2, $payload['layers']);
        $this->assertSame('500.00', $payload['layers'][0]['markup']);
        $this->assertSame('200.00', $payload['layers'][1]['markup']);
    }

    public function test_a_main_office_member_may_see_the_net(): void
    {
        $user = User::factory()->create(['agency_id' => $this->mainOffice->id]);

        $payload = AgencyPriceView::forOffer($this->breakdown($this->mainOffice), $user)->toArray();

        // Level 0 has nothing above it, so its cost IS the net — hiding it would hide a
        // number they already own.
        $this->assertSame('5000.00', $payload['net']);
        $this->assertSame('5000.00', $payload['cost']);
        $this->assertSame('5500.00', $payload['sell']);
    }

    // ---------------------------------------------------------- own  preview ----

    /**
     * The agency's own ladder, against a figure the agency typed.
     *
     * Nothing is secret here — they supplied the number — so this is the channel that
     * lets them check their own rule without reading anything off a live fare.
     */
    public function test_the_preview_shows_the_agency_its_own_rung(): void
    {
        $payload = AgencyPriceView::forOwnLadder(
            $this->breakdown(),
            $this->agencyUser(),
            Money::of(5000),
        )->toArray();

        $this->assertSame('5000.00', $payload['cost'], 'the figure they typed, unchanged');
        $this->assertSame('200.00', $payload['markup'], 'their own rung');
        $this->assertSame('5200.00', $payload['sell'], 'their figure plus their rung');
    }

    /**
     * The engine computed the Main Office rung too. It must not come back.
     *
     * Returning the chain's real cost of ₱5,500 against a typed ₱5,000 would hand over
     * the Main Office's ₱500 as the difference.
     */
    public function test_the_preview_never_gives_up_the_main_office_rung(): void
    {
        $payload = AgencyPriceView::forOwnLadder(
            $this->breakdown(),
            $this->agencyUser(),
            Money::of(5000),
        )->toArray();

        $json = json_encode($payload);

        $this->assertNotSame('5500.00', $payload['cost']);
        $this->assertStringNotContainsString('5500.00', $json);
        $this->assertStringNotContainsString('5700.00', $json, 'the real selling price');
        $this->assertStringNotContainsString('Main Office', $json);
        $this->assertArrayNotHasKey('net', $payload);
        $this->assertArrayNotHasKey('layers', $payload);
    }

    /**
     * The preview names the rule that fired, so "why this one?" is answerable.
     *
     * These are the agency's OWN matching criteria, echoed back — nothing about the
     * supplier rate or the level above is in them.
     */
    public function test_the_preview_says_which_of_their_rules_fired(): void
    {
        $payload = AgencyPriceView::forOwnLadder(
            $this->breakdown(),
            $this->agencyUser(),
            Money::of(5000),
        )->toArray();

        $layer = $payload['ownLayers'][0];

        $this->assertSame('fixed', $layer['calcType']);
        $this->assertSame('200.00', $layer['markup']);
        $this->assertArrayHasKey('product', $layer);
        $this->assertArrayHasKey('scope', $layer);
        $this->assertArrayHasKey('priority', $layer);
        $this->assertArrayHasKey('basis', $layer);
    }

    public function test_the_preview_rung_carries_no_basis_amount(): void
    {
        $payload = AgencyPriceView::forOwnLadder(
            $this->breakdown(),
            $this->agencyUser(),
            Money::of(5000),
        )->toArray();

        // basisAmount IS the supplier net on a net-basis rule, which is how it leaks
        // inside "their own" layer without anyone noticing.
        $this->assertArrayNotHasKey('basisAmount', $payload['ownLayers'][0]);
        $this->assertArrayNotHasKey('runningTotal', $payload['ownLayers'][0]);
        $this->assertSame(1, $payload['ownLayers'][0]['level']);
        $this->assertSame('Agency ABC', $payload['ownLayers'][0]['agencyName']);
    }
}
