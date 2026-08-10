<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'currency' => 'PHP',
            'balance' => '0.00',
        ];
    }

    public function withBalance(string $balance): static
    {
        return $this->state(fn (array $attributes) => ['balance' => $balance]);
    }
}
