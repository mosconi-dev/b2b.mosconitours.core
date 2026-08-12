<?php

namespace Tests\Feature\TboAir;

use App\Services\TboAir\DTO\Ssr;
use Tests\TestCase;

/**
 * How SSR options read once they reach an agent.
 *
 * Written against what Spicejet actually returned for a DEL–DXB return: 41 meals, three
 * of them with no name at all and thirty of them free.
 */
class SsrOptionsTest extends TestCase
{
    private function ssr(array $meals): Ssr
    {
        return Ssr::fromResponse(['Response' => ['TraceId' => 't', 'MealDynamic' => [$meals]]], 'OB1');
    }

    /**
     * TBO sends the description key present but empty, so `data_get`'s default never
     * fires — those rows rendered as a price with no dish beside it.
     */
    public function test_a_meal_with_no_description_still_gets_a_name(): void
    {
        $meals = $this->ssr([
            ['Code' => '2', 'AirlineDescription' => '', 'Description' => '', 'Price' => 0, 'Currency' => 'PHP'],
        ])->toArray()['meals'];

        $this->assertSame('Meal 2', $meals[0]['label']);
    }

    public function test_it_prefers_the_airlines_own_wording(): void
    {
        $meals = $this->ssr([
            ['Code' => 'HFML', 'AirlineDescription' => 'Chicken masala in multigrain bread',
                'Description' => 'Hot meal', 'Price' => 384.96, 'Currency' => 'PHP'],
        ])->toArray()['meals'];

        $this->assertSame('Chicken masala in multigrain bread', $meals[0]['label']);
    }

    /**
     * `Description` is a meal-*type* code, not words.
     *
     * Every option in the live Spicejet response carried `"Description": 2`. Reading
     * it as a label put a bare "2" in the list beside real dish names — which is
     * exactly what shipped before this, and what an earlier version of this test
     * wrongly asserted was correct.
     */
    public function test_it_never_uses_the_numeric_description_as_a_name(): void
    {
        $meals = $this->ssr([
            ['Code' => 'VGML', 'AirlineDescription' => '', 'Description' => 2,
                'Price' => 0, 'Currency' => 'PHP'],
        ])->toArray()['meals'];

        $this->assertSame('Meal VGML', $meals[0]['label']);
    }

    /**
     * TBO lists its own "none" rows. We express none by sending no entry at all, so
     * listing them puts two different nothings in front of the agent.
     */
    public function test_it_drops_tbos_own_none_options(): void
    {
        $ssr = Ssr::fromResponse([
            'Response' => [
                'TraceId' => 't',
                'MealDynamic' => [[
                    ['Code' => 'NoMeal', 'AirlineDescription' => '', 'Price' => 0, 'Origin' => 'DEL', 'Destination' => 'DXB'],
                    ['Code' => 'VGS1', 'AirlineDescription' => 'Veg Sandwich', 'Price' => 0, 'Origin' => 'DEL', 'Destination' => 'DXB'],
                ]],
                'Baggage' => [[
                    ['Code' => 'NoBaggage', 'Weight' => 0, 'Price' => 0, 'Origin' => 'DEL', 'Destination' => 'DXB'],
                    ['Code' => 'PBAG20', 'Weight' => 20, 'Price' => 1200, 'Origin' => 'DEL', 'Destination' => 'DXB'],
                ]],
            ],
        ], 'OB1')->toArray();

        $this->assertSame(['Veg Sandwich'], array_column($ssr['meals'], 'label'));
        $this->assertSame(['PBAG20'], array_column($ssr['baggage'], 'code'));
    }

    /**
     * A code is not unique — TBO repeats it per segment at different prices — so the
     * leg is part of the identity. Without it, an outbound choice resolves to the
     * inbound's row and the wrong price is charged.
     */
    public function test_the_same_code_on_two_legs_stays_two_options(): void
    {
        $ssr = Ssr::fromResponse([
            'Response' => [
                'TraceId' => 't',
                'MealDynamic' => [[
                    ['Code' => 'VGML', 'AirlineDescription' => 'Veg Meal', 'Price' => 384.96, 'Origin' => 'DEL', 'Destination' => 'DXB'],
                    ['Code' => 'VGML', 'AirlineDescription' => 'Veg Meal', 'Price' => 337.52, 'Origin' => 'BOM', 'Destination' => 'DEL'],
                ]],
            ],
        ], 'OB1');

        $this->assertCount(2, $ssr->meals);
        $this->assertSame(384.96, $ssr->meal('VGML|DEL|DXB')['price']);
        $this->assertSame(337.52, $ssr->meal('VGML|BOM|DEL')['price']);

        // Two legs, listed once each.
        $this->assertSame(['DEL|DXB', 'BOM|DEL'], array_column($ssr->legs(), 'key'));
    }

    /** A bare code still resolves, so bookings saved before this still price. */
    public function test_a_bare_code_still_resolves(): void
    {
        $ssr = $this->ssr([
            ['Code' => 'VGML', 'AirlineDescription' => 'Veg Meal', 'Price' => 384.96,
                'Origin' => 'DEL', 'Destination' => 'DXB', 'Currency' => 'PHP'],
        ]);

        $this->assertSame('VGML|DEL|DXB', $ssr->meal('VGML')['key']);
    }
}
