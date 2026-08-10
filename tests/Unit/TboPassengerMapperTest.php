<?php

namespace Tests\Unit;

use App\Services\TboAir\TboPassengerMapper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TboPassengerMapperTest extends TestCase
{
    public function test_it_maps_the_three_titles_tbo_accepts(): void
    {
        $this->assertSame(0, TboPassengerMapper::title('Mr'));
        $this->assertSame(1, TboPassengerMapper::title('Miss'));
        $this->assertSame(2, TboPassengerMapper::title('Mrs'));
    }

    public function test_it_folds_retired_titles_onto_the_nearest_tbo_value(): void
    {
        // The wizard offered these before Phase 4.0, so stored bookings may carry them.
        $this->assertSame(1, TboPassengerMapper::title('Ms'));
        $this->assertSame(0, TboPassengerMapper::title('Mstr'));
    }

    public function test_it_is_case_and_whitespace_insensitive(): void
    {
        $this->assertSame(2, TboPassengerMapper::title('  mrs '));
        $this->assertSame(1, TboPassengerMapper::gender('MALE'));
        $this->assertSame(3, TboPassengerMapper::paxType(' infant'));
    }

    public function test_it_rejects_an_unmappable_title(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TboPassengerMapper::title('Dr');
    }

    public function test_it_maps_gender(): void
    {
        $this->assertSame(1, TboPassengerMapper::gender('M'));
        $this->assertSame(2, TboPassengerMapper::gender('F'));
    }

    /**
     * Gender is nullable for us but mandatory for TBO, and airlines match it against
     * ID — so a missing value must stop the booking rather than default to male.
     */
    public function test_it_refuses_to_guess_a_missing_gender(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TboPassengerMapper::gender(null);
    }

    public function test_it_maps_passenger_type(): void
    {
        $this->assertSame(1, TboPassengerMapper::paxType('Adult'));
        $this->assertSame(2, TboPassengerMapper::paxType('Child'));
        $this->assertSame(3, TboPassengerMapper::paxType('Infant'));
    }

    public function test_it_rejects_a_fare_type_we_do_not_sell(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TboPassengerMapper::paxType('Senior');
    }
}
