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

    public function test_it_falls_back_to_the_generic_description(): void
    {
        $meals = $this->ssr([
            ['Code' => 'VGML', 'AirlineDescription' => '', 'Description' => 'Vegetarian Meal',
                'Price' => 0, 'Currency' => 'PHP'],
        ])->toArray()['meals'];

        $this->assertSame('Vegetarian Meal', $meals[0]['label']);
    }
}
