<?php

namespace Tests\Unit;

use App\Enums\TripType;
use PHPUnit\Framework\TestCase;

class TripTypeTest extends TestCase
{
    public function test_journey_type_mapping(): void
    {
        $this->assertSame(1, TripType::OneWay->journeyType());
        $this->assertSame(2, TripType::Round->journeyType());
        $this->assertSame(3, TripType::Multi->journeyType());
    }

    public function test_from_front_end_values(): void
    {
        $this->assertSame(TripType::Round, TripType::from('round'));
        $this->assertSame(TripType::OneWay, TripType::from('oneway'));
        $this->assertSame(TripType::Multi, TripType::from('multi'));
    }
}
