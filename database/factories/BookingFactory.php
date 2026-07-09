<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        return [
            'reference' => 'MT-'.strtoupper(Str::random(8)),
            'user_id' => User::factory(),
            'environment' => 'test',
            'status' => BookingStatus::Quoted,
            'trace_id' => 'trace-'.Str::random(8),
            'result_index' => Str::random(400),
            'is_lcc' => true,
            'currency' => 'PHP',
            'total_amount' => 6400,
            'quote' => ['price' => ['currency' => 'PHP', 'offeredFare' => 6400], 'isLcc' => true],
            'pax' => [
                ['type' => 'Adult', 'title' => 'Mr', 'firstName' => 'Juan', 'lastName' => 'Cruz'],
            ],
            'contact' => ['email' => 'agent@example.com', 'phone' => '09170000000'],
        ];
    }

    public function status(BookingStatus $status): self
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function live(): self
    {
        return $this->state(fn () => ['environment' => 'live']);
    }
}
