<?php

namespace Tests\Unit\Pricing;

use App\Services\Pricing\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_normalises_every_shape_money_arrives_in(): void
    {
        $this->assertSame('5000.00', (string) Money::of(5000));
        $this->assertSame('5000.00', (string) Money::of('5000'));
        $this->assertSame('5000.50', (string) Money::of(5000.5));
        $this->assertSame('5000.00', (string) Money::of('5000.00'));
    }

    public function test_a_thousands_separator_does_not_silently_become_one_peso(): void
    {
        // bcmath reads "1,500.00" as 1. This is the trap WalletService::normalize()
        // exists to avoid, and markup is the same money.
        $this->assertSame('1500.00', (string) Money::of('1,500.00'));
    }

    public function test_it_refuses_something_that_is_not_a_number(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('five thousand');
    }

    public function test_addition_and_subtraction(): void
    {
        $this->assertSame('5700.00', (string) Money::of(5000)->plus(Money::of(700)));
        $this->assertSame('700.00', (string) Money::of(5700)->minus(Money::of(5000)));
    }

    public function test_percentages(): void
    {
        $this->assertSame('500.00', (string) Money::of(5000)->percent(10));
        $this->assertSame('250.00', (string) Money::of(5000)->percent('5'));
        $this->assertSame('0.00', (string) Money::of(5000)->percent(0));
    }

    public function test_a_percentage_is_not_rounded_twice(): void
    {
        // 10% of 3,333.33 is 333.333 — one rounding, at the end.
        $this->assertSame('333.33', (string) Money::of('3333.33')->percent(10));

        // 7.5% of 1,234.56 is 92.592.
        $this->assertSame('92.59', (string) Money::of('1234.56')->percent('7.5'));
    }

    public function test_clamping_between_a_floor_and_a_cap(): void
    {
        $markup = Money::of(100);

        $this->assertSame('500.00', (string) $markup->clamp(Money::of(500), null), 'floor lifts it');
        $this->assertSame('100.00', (string) $markup->clamp(Money::of(50), Money::of(3000)), 'within range');
        $this->assertSame('80.00', (string) $markup->clamp(null, Money::of(80)), 'cap holds it down');
        $this->assertSame('100.00', (string) $markup->clamp(null, null), 'no bounds, no change');
    }

    public function test_rounding_up_to_a_step(): void
    {
        $this->assertSame('5850.00', (string) Money::of('5847.63')->roundUpTo(50));
        $this->assertSame('5850.00', (string) Money::of('5847.63')->roundUpTo(10));
        $this->assertSame('5900.00', (string) Money::of('5847.63')->roundUpTo(100));
        $this->assertSame('5847.63', (string) Money::of('5847.63')->roundUpTo(0), 'no step, no change');
        $this->assertSame('5847.63', (string) Money::of('5847.63')->roundUpTo(1));
        $this->assertSame('5850.00', (string) Money::of('5850.00')->roundUpTo(50), 'already on the step');
    }

    public function test_comparisons(): void
    {
        $this->assertTrue(Money::of(100)->greaterThan(Money::of(99)));
        $this->assertTrue(Money::of(99)->lessThan(Money::of(100)));
        $this->assertTrue(Money::of('100.00')->equals(Money::of(100)));
        $this->assertTrue(Money::zero()->isZero());
        $this->assertTrue(Money::of(-1)->isNegative());
        $this->assertFalse(Money::zero()->isNegative());
    }

    public function test_it_is_immutable(): void
    {
        $original = Money::of(5000);
        $original->plus(Money::of(700));

        $this->assertSame('5000.00', (string) $original);
    }

    public function test_formatting_for_display(): void
    {
        $this->assertSame('5,700.00', Money::of(5700)->formatted());
    }
}
