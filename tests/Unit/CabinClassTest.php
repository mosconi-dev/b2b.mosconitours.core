<?php

namespace Tests\Unit;

use App\Enums\CabinClass;
use PHPUnit\Framework\TestCase;

class CabinClassTest extends TestCase
{
    public function test_tbo_code_mapping(): void
    {
        $this->assertSame(1, CabinClass::Any->tboCode());
        $this->assertSame(2, CabinClass::Economy->tboCode());
        $this->assertSame(3, CabinClass::Premium->tboCode());
        $this->assertSame(4, CabinClass::Business->tboCode());
        $this->assertSame(6, CabinClass::First->tboCode());
    }

    public function test_from_front_end_values(): void
    {
        $this->assertSame(CabinClass::Any, CabinClass::from('any'));
        $this->assertSame(CabinClass::Economy, CabinClass::from('economy'));
        $this->assertSame(CabinClass::First, CabinClass::from('first'));
    }
}
