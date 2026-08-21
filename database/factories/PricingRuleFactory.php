<?php

namespace Database\Factories;

use App\Enums\CalcType;
use App\Enums\PricingBasis;
use App\Models\PricingRule;
use App\Models\PricingStrategy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PricingRule>
 */
class PricingRuleFactory extends Factory
{
    protected $model = PricingRule::class;

    public function definition(): array
    {
        return [
            'pricing_strategy_id' => PricingStrategy::factory(),
            'product' => PricingRule::ANY,
            'supplier' => null,
            'scope' => 'any',
            'matchers' => null,
            'calc_type' => CalcType::Fixed,
            'value' => '500.0000',
            'basis' => PricingBasis::Net,
            'applies_to' => 'total',
            'priority' => 100,
            'is_active' => true,
        ];
    }

    public function fixed(string|float $amount): static
    {
        return $this->state(['calc_type' => CalcType::Fixed, 'value' => $amount]);
    }

    public function percentage(string|float $percent): static
    {
        return $this->state(['calc_type' => CalcType::PercentageMarkup, 'value' => $percent]);
    }

    public function margin(string|float $percent): static
    {
        return $this->state(['calc_type' => CalcType::PercentageMargin, 'value' => $percent]);
    }

    public function perPax(string|float $amount): static
    {
        return $this->state(['calc_type' => CalcType::PerPax, 'value' => $amount]);
    }

    public function perRoomNight(string|float $amount): static
    {
        return $this->state(['calc_type' => CalcType::PerRoomNight, 'value' => $amount]);
    }

    public function none(): static
    {
        return $this->state(['calc_type' => CalcType::None, 'value' => 0]);
    }

    public function forProduct(string $product): static
    {
        return $this->state(['product' => $product]);
    }

    public function scoped(string $scope): static
    {
        return $this->state(['scope' => $scope]);
    }

    public function priority(int $priority): static
    {
        return $this->state(['priority' => $priority]);
    }
}
