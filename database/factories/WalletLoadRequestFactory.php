<?php

namespace Database\Factories;

use App\Enums\LoadRequestStatus;
use App\Models\Agency;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLoadRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WalletLoadRequest>
 */
class WalletLoadRequestFactory extends Factory
{
    protected $model = WalletLoadRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $agency = Agency::factory();

        return [
            'reference' => 'LR-'.strtoupper(Str::random(8)),
            'agency_id' => $agency,
            'wallet_id' => Wallet::factory()->state(fn (array $attrs) => ['agency_id' => $attrs['agency_id'] ?? $agency]),
            'amount' => '1000.00',
            'currency' => 'PHP',
            'status' => LoadRequestStatus::Pending,
            'payment_reference' => 'BDO-'.Str::random(6),
            'requested_by' => User::factory(),
        ];
    }

    public function status(LoadRequestStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
