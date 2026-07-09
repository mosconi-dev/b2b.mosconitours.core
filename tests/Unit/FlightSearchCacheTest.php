<?php

namespace Tests\Unit;

use App\Enums\CabinClass;
use App\Enums\TripType;
use App\Services\TboAir\DTO\SearchInput;
use App\Services\TboAir\FlightSearchCache;
use PHPUnit\Framework\TestCase;

class FlightSearchCacheTest extends TestCase
{
    private function input(string $departure = '2026-07-15'): SearchInput
    {
        return new SearchInput(
            TripType::OneWay,
            CabinClass::Economy,
            1, 0, 0,
            [['origin' => 'MNL', 'destination' => 'MPH', 'departure' => $departure]],
            null,
        );
    }

    public function test_key_is_deterministic_for_same_input(): void
    {
        $cache = new FlightSearchCache(300);

        $this->assertSame($cache->key(1, 'test', $this->input()), $cache->key(1, 'test', $this->input()));
    }

    public function test_key_differs_by_user_params_and_environment(): void
    {
        $cache = new FlightSearchCache(300);

        $this->assertNotSame($cache->key(1, 'test', $this->input()), $cache->key(2, 'test', $this->input()));
        $this->assertNotSame($cache->key(1, 'test', $this->input('2026-07-15')), $cache->key(1, 'test', $this->input('2026-08-01')));
        // Same user + params but different environment must not collide.
        $this->assertNotSame($cache->key(1, 'test', $this->input()), $cache->key(1, 'live', $this->input()));
    }

    public function test_key_is_namespaced_by_environment_and_user(): void
    {
        $cache = new FlightSearchCache(300);

        $this->assertStringStartsWith('flight_search:test:7:', $cache->key(7, 'test', $this->input()));
    }
}
