<?php

namespace Tests\Unit;

use App\Enums\TravelScope;
use PHPUnit\Framework\TestCase;

class TravelScopeTest extends TestCase
{
    public function test_values_are_the_strings_the_front_end_matches_on(): void
    {
        // The results-card chips compare against these literals.
        $this->assertSame('domestic', TravelScope::Domestic->value);
        $this->assertSame('international', TravelScope::International->value);
        $this->assertSame(['domestic', 'international'], TravelScope::values());
    }

    public function test_labels(): void
    {
        $this->assertSame('Domestic', TravelScope::Domestic->label());
        $this->assertSame('International', TravelScope::International->label());
    }

    public function test_is_domestic(): void
    {
        $this->assertTrue(TravelScope::Domestic->isDomestic());
        $this->assertFalse(TravelScope::International->isDomestic());
    }

    public function test_every_case_has_badge_classes(): void
    {
        foreach (TravelScope::cases() as $scope) {
            $this->assertNotSame('', $scope->badgeClasses());
        }
    }
}
