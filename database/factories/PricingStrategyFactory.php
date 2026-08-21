<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\PricingStrategy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PricingStrategy>
 */
class PricingStrategyFactory extends Factory
{
    protected $model = PricingStrategy::class;

    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'name' => 'Standard markup',
            'is_active' => true,
        ];
    }

    public function paused(): static
    {
        return $this->state(['is_active' => false]);
    }
}
