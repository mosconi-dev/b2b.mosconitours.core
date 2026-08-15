<?php

namespace Tests\Feature\Pricing;

use App\Enums\BookingProduct;
use App\Enums\Supplier;
use App\Enums\TravelScope;
use App\Models\Agency;
use App\Models\BookingPriceLayer;
use App\Models\PricingRule;
use App\Models\PricingStrategy;
use App\Models\User;
use App\Services\Pricing\NetPrice;
use App\Services\Pricing\OfferPricer;
use App\Services\Pricing\PricingContext;
use App\Services\Pricing\PricingEngine;
use App\Services\Pricing\StrategyResolver;
use App\Services\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4: the engine reaches a payload, and a marked-up price never leaks the net it
 * was built from.
 */
class PricingActivationTest extends TestCase
{
    use RefreshDatabase;

    private Agency $mainOffice;

    private Agency $agency;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mainOffice = Agency::factory()->create(['name' => 'Main Office']);
        $this->agency = Agency::factory()->create(['name' => 'Agency ABC']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id]);

        app(Settings::class)->set(StrategyResolver::MAIN_OFFICE_SETTING, (string) $this->mainOffice->id);
    }

    private function withMarkups(float $office = 500, float $agency = 200): void
    {
        PricingRule::factory()->fixed($office)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id])->id,
        ]);
        PricingRule::factory()->fixed($agency)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->agency->id])->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function flightPayload(float $net = 5000): array
    {
        return [
            'currency' => 'PHP',
            'results' => [[
                'resultIndex' => 'OB1',
                'airlineCode' => 'PR',
                'isLcc' => false,
                'isRefundable' => true,
                'cabin' => 'Economy',
                'stops' => 0,
                'scope' => 'domestic',
                'departure' => ['code' => 'MNL', 'time' => '2026-09-11T08:00:00'],
                'arrival' => ['code' => 'CEB'],
                'price' => ['currency' => 'PHP', 'baseFare' => 4000.0, 'tax' => 1000.0, 'offeredFare' => $net, 'publishedFare' => $net],
            ]],
        ];
    }

    // --------------------------------------------------------- flight search ----

    public function test_the_headline_fare_becomes_the_selling_price(): void
    {
        $this->withMarkups();

        $priced = app(OfferPricer::class)->flightSearch($this->flightPayload(), $this->agent);

        // The list, the sort and the filters all read offeredFare, so they move together.
        $this->assertSame(5700.0, $priced['results'][0]['price']['offeredFare']);
    }

    public function test_the_supplier_fare_components_are_stripped_for_an_agency(): void
    {
        $this->withMarkups();

        $price = app(OfferPricer::class)->flightSearch($this->flightPayload(), $this->agent)['results'][0]['price'];

        // Any one of these beside a sell price gives up the net in one subtraction.
        $this->assertArrayNotHasKey('baseFare', $price);
        $this->assertArrayNotHasKey('tax', $price);
        $this->assertArrayNotHasKey('publishedFare', $price);
        $this->assertSame('PHP', $price['currency']);
    }

    public function test_platform_staff_keep_the_supplier_components(): void
    {
        $this->withMarkups();
        $staff = User::factory()->create(['agency_id' => null]);

        $price = app(OfferPricer::class)->flightSearch($this->flightPayload(), $staff)['results'][0]['price'];

        $this->assertSame(4000.0, $price['baseFare']);
        $this->assertSame(1000.0, $price['tax']);
    }

    public function test_the_net_appears_nowhere_in_an_agency_payload(): void
    {
        $this->withMarkups();

        $json = json_encode(app(OfferPricer::class)->flightSearch($this->flightPayload(), $this->agent));

        $this->assertStringNotContainsString('5000', $json);
        $this->assertStringNotContainsString('Main Office', $json);
    }

    public function test_an_unconfigured_platform_sells_at_net_rather_than_failing(): void
    {
        // An installation that has never set a pricing root behaves exactly as it did
        // before pricing existed. Taking every search down until someone visits an admin
        // screen would be a worse failure than the one it prevents.
        app(Settings::class)->forget(StrategyResolver::MAIN_OFFICE_SETTING);

        $priced = app(OfferPricer::class)->flightSearch($this->flightPayload(), $this->agent);

        $this->assertSame(5000.0, $priced['results'][0]['price']['offeredFare']);
    }

    // ------------------------------------------------------------ fare quote ----

    public function test_the_fare_breakdown_is_allocated_and_sums_to_the_total(): void
    {
        $this->withMarkups();

        $quote = [
            'scope' => 'domestic',
            'isLcc' => false,
            'price' => ['currency' => 'PHP', 'baseFare' => 4000.0, 'tax' => 1000.0, 'offeredFare' => 5000.0],
            'fareBreakdown' => [
                ['passengerType' => 'Adult', 'count' => 2, 'baseFare' => 3000.0, 'tax' => 750.0],
                ['passengerType' => 'Child', 'count' => 1, 'baseFare' => 1000.0, 'tax' => 250.0],
            ],
            'trips' => [],
        ];

        $priced = app(OfferPricer::class)->fareQuote($quote, $this->agent);

        $this->assertSame(5700.0, $priced['price']['offeredFare']);

        // The rows must add up to the price beside them, or the page reads as broken.
        $summed = array_sum(array_column($priced['fareBreakdown'], 'amountTotal'));
        $this->assertSame(5700.0, round($summed, 2));

        // 3,750 of 5,000 is 75% → 4,275; the remainder falls to the last row.
        $this->assertSame(4275.0, $priced['fareBreakdown'][0]['amountTotal']);
        $this->assertSame(1425.0, $priced['fareBreakdown'][1]['amountTotal']);
        $this->assertSame(2137.5, $priced['fareBreakdown'][0]['amount'], 'per passenger');

        // And the supplier split is gone.
        $this->assertArrayNotHasKey('baseFare', $priced['fareBreakdown'][0]);
        $this->assertArrayNotHasKey('tax', $priced['fareBreakdown'][0]);
    }

    // ---------------------------------------------------------- hotel search ----

    public function test_hotel_rooms_are_priced_and_the_cheapest_headline_follows(): void
    {
        $this->withMarkups();

        $payload = ['offers' => [[
            'hotelCode' => '1022346',
            'currency' => 'PHP',
            'countryCode' => 'PH',
            'cityCode' => '127116',
            'rating' => 4,
            'scope' => 'domestic',
            'lowestFare' => 5000.0,
            'rooms' => [
                ['bookingCode' => 'A', 'totalFare' => 5000.0, 'totalTax' => 300.0, 'nightlyRate' => 2500.0, 'dayRates' => [2350.0, 2350.0], 'isRefundable' => true],
                ['bookingCode' => 'B', 'totalFare' => 8000.0, 'totalTax' => 400.0, 'nightlyRate' => null, 'dayRates' => [3800.0, 4200.0], 'isRefundable' => false],
            ],
        ]]];

        $priced = app(OfferPricer::class)->hotelSearch($payload, $this->agent, nights: 2, rooms: 1);
        $offer = $priced['offers'][0];

        $this->assertSame(5700.0, $offer['rooms'][0]['totalFare']);
        $this->assertSame(8700.0, $offer['rooms'][1]['totalFare']);
        $this->assertSame(5700.0, $offer['lowestFare'], 'the card headline follows the cheapest room');

        // Per-night base prices sum to the net fare, so they hand over the cost.
        $this->assertArrayNotHasKey('dayRates', $offer['rooms'][0]);

        // Recomputed from the sell price: 5,700 over two nights, one room.
        $this->assertSame(2850.0, $offer['rooms'][0]['nightlyRate']);

        // Null means the supplier priced the nights unevenly — averaging them would be a
        // number the agent could not defend.
        $this->assertNull($offer['rooms'][1]['nightlyRate']);

        // A component, not a total: it reveals nothing and the sell price does include it.
        $this->assertSame(300.0, $offer['rooms'][0]['totalTax']);
    }

    // -------------------------------------------------------------- the money ----

    public function test_the_wallet_is_debited_cost_and_the_booking_records_the_ladder(): void
    {
        $this->withMarkups();

        $price = app(PricingEngine::class)->quote(
            new PricingContext(
                product: BookingProduct::Flight,
                supplier: Supplier::TboAir,
                scope: TravelScope::Domestic,
                net: NetPrice::of(5000),
            ),
            $this->agency,
        );

        // The four figures a marked-up booking carries, and which one moves money.
        $this->assertSame('5000.00', (string) $price->net->amount, 'goes to the supplier');
        $this->assertSame('5500.00', (string) $price->cost(), 'leaves the wallet');
        $this->assertSame('5700.00', (string) $price->sell->amount, 'shown, printed, reported');
        $this->assertSame('700.00', (string) $price->markupTotal());

        $this->assertCount(2, $price->layers);
        $this->assertSame(BookingPriceLayer::MAIN_OFFICE, $price->layers[0]->level);
        $this->assertSame(BookingPriceLayer::AGENCY, $price->layers[1]->level);
        $this->assertSame($this->mainOffice->id, $price->layers[0]->agency->id);
        $this->assertSame($this->agency->id, $price->layers[1]->agency->id);
    }

    public function test_the_agency_keeps_its_own_margin_and_the_platform_takes_only_its_own(): void
    {
        $this->withMarkups();

        $price = app(PricingEngine::class)->quote(
            new PricingContext(
                product: BookingProduct::Flight,
                supplier: Supplier::TboAir,
                scope: TravelScope::Domestic,
                net: NetPrice::of(5000),
            ),
            $this->agency,
        );

        // Model A: the platform collects net + its own markup, and the agency's 200 is
        // margin from its own customer that this platform is not party to.
        $platformTake = $price->cost()->minus($price->net->amount);

        $this->assertSame('500.00', (string) $platformTake);
        $this->assertSame('200.00', (string) $price->ownMargin());
    }

    // ------------------------------------------------------------- add-ons ----

    /**
     * The quote the add-ons hang off, and the add-on menu TBO returns for it.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function quoteAndSsr(float $net = 5000): array
    {
        return [
            [
                'scope' => 'domestic',
                'isLcc' => true,
                'price' => ['currency' => 'PHP', 'baseFare' => 4000.0, 'tax' => 1000.0, 'offeredFare' => $net],
                'fareBreakdown' => [['passengerType' => 'Adult', 'count' => 1, 'baseFare' => 4000.0, 'tax' => 1000.0]],
                'trips' => [],
            ],
            [
                'traceId' => 'T1',
                'resultIndex' => 'OB1',
                'baggage' => [['key' => 'B5|MNL|CEB', 'code' => 'B5', 'label' => '5 kg', 'price' => 1000.0]],
                'meals' => [
                    ['key' => 'M1|MNL|CEB', 'code' => 'M1', 'label' => 'Cake', 'price' => 500.0],
                    ['key' => 'M0|MNL|CEB', 'code' => 'M0', 'label' => 'Fruit platter', 'price' => 0.0],
                ],
            ],
        ];
    }

    public function test_add_ons_are_shown_at_the_selling_price_not_the_supplier_price(): void
    {
        // 10% for the Main Office, 20% for the agency — both on net, cumulative.
        PricingRule::factory()->percentage(10)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id])->id,
        ]);
        PricingRule::factory()->percentage(20)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->agency->id])->id,
        ]);

        [$quote, $ssr] = $this->quoteAndSsr();

        $priced = app(OfferPricer::class)->ssr($ssr, $quote, $this->agent);

        // 1,000 of supplier cost sells for 1,300; 500 sells for 650. Left at net, these
        // were both our cost, printed in a picker for an agency to read.
        $this->assertSame(1300.0, $priced['baggage'][0]['price']);
        $this->assertSame(650.0, $priced['meals'][0]['price']);

        // A complimentary meal stays complimentary.
        $this->assertSame(0.0, $priced['meals'][1]['price']);
    }

    public function test_the_quoted_total_is_the_one_the_booking_is_written_at(): void
    {
        PricingRule::factory()->percentage(10)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id])->id,
        ]);
        PricingRule::factory()->percentage(20)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->agency->id])->id,
        ]);

        [$quote, $ssr] = $this->quoteAndSsr();
        $pricer = app(OfferPricer::class);

        // What the wizard puts in front of the agent: a priced fare, plus the add-ons
        // it lists beside it.
        $fare = $pricer->fareQuote($quote, $this->agent)['price']['offeredFare'];
        $priced = $pricer->ssr($ssr, $quote, $this->agent);
        $shown = $fare + $priced['baggage'][0]['price'] + $priced['meals'][0]['price'];

        // What BookingService charges: ancillaries folded into net, priced once.
        $charged = app(PricingEngine::class)->quote(
            new PricingContext(
                product: BookingProduct::Flight,
                supplier: Supplier::TboAir,
                scope: TravelScope::Domestic,
                net: NetPrice::of(5000 + 1000 + 500),
            ),
            $this->agency,
        )->sell->amount->toFloat();

        $this->assertSame($charged, $shown, 'the agent approves the total they are charged');
    }

    /**
     * The same, on figures that do not divide cleanly.
     *
     * Each add-on is rounded to centavos on its own and the browser adds them up, while
     * the booking rounds once over the whole net — so the two can part company by a
     * centavo per add-on. That is the real guarantee, and it is asserted here rather
     * than left to a fixture whose round numbers hide it: a test that claims exactness
     * the system does not offer is worse than no test.
     */
    public function test_the_quoted_total_holds_to_a_centavo_an_add_on_on_awkward_figures(): void
    {
        // The live ladder: 10% Main Office, then 12% + 5% + 250 for the agency.
        $office = PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id]);
        $own = PricingStrategy::factory()->create(['agency_id' => $this->agency->id]);
        PricingRule::factory()->percentage(10)->create(['pricing_strategy_id' => $office->id]);
        PricingRule::factory()->percentage(12)->create(['pricing_strategy_id' => $own->id]);
        PricingRule::factory()->percentage(5)->create(['pricing_strategy_id' => $own->id]);
        PricingRule::factory()->fixed(250)->create(['pricing_strategy_id' => $own->id]);

        // Prices TBO actually quoted for a DEL→DXB add-on menu.
        [$quote, $ssr] = $this->quoteAndSsr(18143.03);
        $ssr['baggage'][0]['price'] = 4277.35;
        $ssr['meals'][0]['price'] = 544.39;

        $pricer = app(OfferPricer::class);
        $shown = $pricer->fareQuote($quote, $this->agent)['price']['offeredFare'];

        $priced = $pricer->ssr($ssr, $quote, $this->agent);
        $shown += $priced['baggage'][0]['price'] + $priced['meals'][0]['price'];

        $charged = app(PricingEngine::class)->quote(
            new PricingContext(
                product: BookingProduct::Flight,
                supplier: Supplier::TboAir,
                scope: TravelScope::Domestic,
                net: NetPrice::of(18143.03 + 4277.35 + 544.39),
            ),
            $this->agency,
        )->sell->amount->toFloat();

        $this->assertEqualsWithDelta($charged, $shown, 0.02, 'two add-ons, so at most two centavos');
    }

    public function test_a_flat_rule_is_charged_once_for_the_booking_not_once_per_add_on(): void
    {
        // A ₱250 ticketing fee, of the kind an agency adds on top of its percentages.
        $this->withMarkups(office: 500, agency: 250);

        [$quote, $ssr] = $this->quoteAndSsr();

        $priced = app(OfferPricer::class)->ssr($ssr, $quote, $this->agent);

        // Pricing each option on its own would have made every bag cost 750 more and
        // every meal 750 more. The marginal price carries no flat at all.
        $this->assertSame(1000.0, $priced['baggage'][0]['price']);
        $this->assertSame(500.0, $priced['meals'][0]['price']);
    }

    public function test_an_agency_never_sees_the_supplier_price_of_an_add_on(): void
    {
        PricingRule::factory()->percentage(10)->create([
            'pricing_strategy_id' => PricingStrategy::factory()->create(['agency_id' => $this->mainOffice->id])->id,
        ]);

        [$quote, $ssr] = $this->quoteAndSsr();

        $json = json_encode(app(OfferPricer::class)->ssr($ssr, $quote, $this->agent));

        $this->assertStringNotContainsString('1000', $json, 'the supplier price of the bag');
        $this->assertStringNotContainsString('500.0', $json, 'the supplier price of the meal');
    }
}
