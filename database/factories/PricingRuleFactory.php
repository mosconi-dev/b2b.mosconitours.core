<?php

namespace Database\Factories;

use App\Enums\CalcType;
use App\Enums\PricingBasis;
use App\Enums\TierMode;
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
            'params' => null,
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

    /**
     * A tier table. Rows are [upTo|null, calcType, value], in the order they are read.
     *
     * @param  array<int, array{0: string|float|null, 1: CalcType, 2: string|float}>  $bands
     */
    public function tiered(array $bands, TierMode $mode = TierMode::Whole): static
    {
        return $this->state([
            'calc_type' => CalcType::Tiered,
            // A tiered rule's numbers are all in its bands; `value` is NOT NULL and unread.
            'value' => 0,
            'params' => ['mode' => $mode->value, 'bands' => array_map(fn (array $band): array => [
                'up_to' => $band[0] === null ? null : (string) $band[0],
                'calc_type' => $band[1]->value,
                'value' => (string) $band[2],
            ], $bands)],
        ]);
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
