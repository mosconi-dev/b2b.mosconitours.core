<?php

namespace Database\Factories;

use App\Enums\AgencyType;
use App\Models\Agency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agency>
 */
class AgencyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('agy-####'),
            'name' => fake()->company(),
            'type' => AgencyType::Outlet,
            'parent_id' => null,
            'is_active' => true,
        ];
    }

    public function mainOffice(): static
    {
        return $this->state(fn (array $attributes) => ['type' => AgencyType::MainOffice]);
    }

    public function outlet(): static
    {
        return $this->state(fn (array $attributes) => ['type' => AgencyType::Outlet]);
    }

    public function itp(): static
    {
        return $this->state(fn (array $attributes) => ['type' => AgencyType::Itp]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
