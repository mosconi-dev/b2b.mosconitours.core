<?php

namespace Tests\Feature\Pricing;

use App\Services\Pricing\PricingContextFactory;
use Tests\TestCase;

/**
 * The keys a rule may narrow on, against the keys a context actually carries.
 *
 * MATCHABLE_KEYS is a hand-kept list and the form validates against it, so drift has two
 * costs and both are silent: a key on the list that no context emits makes a rule that
 * never fires, and a key emitted but missing from the list is one an administrator is
 * refused for no reason.
 *
 * The second assertion is the one that matters most. A flight is priced twice — once from
 * a search offer, once from the fare quote at booking — and the two must carry the same
 * keys, or a rule fires at search, misses at booking, and the price moves for a reason
 * the supplier had nothing to do with.
 */
class MatchableKeysTest extends TestCase
{
    private function factory(): PricingContextFactory
    {
        return app(PricingContextFactory::class);
    }

    public function test_a_flight_offer_carries_exactly_the_keys_a_rule_may_match_on(): void
    {
        $this->assertSame(
            PricingContextFactory::MATCHABLE_KEYS['flight'],
            array_keys($this->factory()->forFlightOffer([])->attributes),
        );
    }

    public function test_a_hotel_room_carries_exactly_the_keys_a_rule_may_match_on(): void
    {
        $this->assertSame(
            PricingContextFactory::MATCHABLE_KEYS['hotel'],
            array_keys($this->factory()->forHotelRoom([], [])->attributes),
        );
    }

    public function test_search_and_booking_narrow_a_flight_on_the_same_keys(): void
    {
        // Both are the same product being priced at two moments. A key present in one
        // and absent from the other is a price that changes between them.
        $atSearch = array_keys($this->factory()->forFlightOffer([])->attributes);
        $atBooking = array_keys($this->factory()->forFareQuote([])->attributes);

        sort($atSearch);
        sort($atBooking);

        $this->assertSame($atSearch, $atBooking);
    }

    public function test_an_all_products_rule_may_match_on_any_product_s_keys(): void
    {
        $any = PricingContextFactory::matchableKeys('*');

        $this->assertContains('airline', $any);
        $this->assertContains('rating', $any);
        $this->assertSame(array_unique($any), $any, 'isRefundable is on both products and must appear once');
    }

    public function test_an_unknown_product_falls_back_to_everything_rather_than_nothing(): void
    {
        // Refusing every key for a product nobody has taught this class about would make
        // the form reject narrowing that the engine would have honoured.
        $this->assertSame(
            PricingContextFactory::matchableKeys('*'),
            PricingContextFactory::matchableKeys('transfer'),
        );
    }
}
