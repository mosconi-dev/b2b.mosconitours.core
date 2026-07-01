<?php

namespace Tests\Unit;

use App\Enums\CabinClass;
use App\Enums\TripType;
use App\Services\TboAir\DTO\SearchInput;
use PHPUnit\Framework\TestCase;

class SearchInputTest extends TestCase
{
    public function test_to_array_is_canonical(): void
    {
        $input = new SearchInput(
            TripType::Round,
            CabinClass::Business,
            2, 1, 0,
            [['origin' => 'MNL', 'destination' => 'HKG', 'departure' => '2026-07-04']],
            '2026-07-09',
        );

        $this->assertSame([
            'tripType' => 'round',
            'cabin' => 'business',
            'adults' => 2,
            'children' => 1,
            'infants' => 0,
            'segments' => [['origin' => 'MNL', 'destination' => 'HKG', 'departure' => '2026-07-04']],
            'returnDate' => '2026-07-09',
        ], $input->toArray());
    }
}
